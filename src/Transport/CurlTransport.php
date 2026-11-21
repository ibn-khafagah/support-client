<?php

namespace IbnKhafagah\SupportClient\Transport;

use CURLFile;

/**
 * The default transport: ext-curl and nothing else, so it works in any PHP
 * system. TLS is always verified, redirects are never followed and only
 * http(s) URLs are allowed. Timeouts: 5 s to connect and 20 s in total for
 * every call, downloads included.
 *
 * It sends the headers it is given (SupportClient passes Authorization,
 * Accept and Accept-Language) and adds Accept: application/json when it is
 * missing. Multipart is used whenever files are present.
 */
final class CurlTransport implements Transport
{
    /** Downloads past this size are stopped (an attachment is at most 10 MB). */
    public const MAX_DOWNLOAD_BYTES = 25 * 1024 * 1024;

    /** Uploads and downloads may take up to this long in total, as long as they keep moving. */
    public const FILE_TIMEOUT = 300;

    public function __construct(
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 20,
        private readonly string $userAgent = 'ibnkhafagah-support-client/1.0',
    ) {}

    /**
     * For transfers with files: a 20 s total would need about 1 MB/s for 20 MB,
     * so the total limit is raised and a stalled transfer (under 10 KB/s for
     * 30 s) is stopped instead.
     *
     * @return array<int, int>
     */
    private function slowTransfer(): array
    {
        return [
            CURLOPT_TIMEOUT => max($this->timeout, self::FILE_TIMEOUT),
            CURLOPT_LOW_SPEED_LIMIT => 10 * 1024,
            CURLOPT_LOW_SPEED_TIME => 30,
        ];
    }

    public function request(string $method, string $url, #[\SensitiveParameter] array $headers, array $json = [], array $files = []): array
    {
        $method = strtoupper($method);

        if ($method === 'GET') {
            $options = [CURLOPT_HTTPGET => true];
        } elseif ($files !== []) {
            $fields = Protocol::formFields($json);
            foreach ($files as $field => $file) {
                $fields[$field] = new CURLFile($file['path'], $file['type'], $file['name']);
            }
            // an array body: curl sends multipart/form-data and sets the boundary itself
            $headers = self::without($headers, 'Content-Type');
            // up to 20 MB of files: no fixed total limit, but give up on a stalled upload
            $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $fields] + $this->slowTransfer();
        } else {
            $body = '';
            if ($json !== []) {
                try {
                    $body = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    // NAN/INF or too deep: nothing was sent
                    throw new \InvalidArgumentException('The request body cannot be encoded as JSON: '.$e->getMessage(), 0, $e);
                }
                $headers = self::without($headers, 'Content-Type') + ['Content-Type' => 'application/json'];
            }
            $options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body];
        }

        [$status, $raw] = $this->execute($url, $headers, $options + [CURLOPT_RETURNTRANSFER => true]);

        return ['status' => $status, 'body' => Protocol::decode($status, (string) $raw)];
    }

    public function download(string $url, #[\SensitiveParameter] array $headers): array
    {
        $stream = fopen('php://temp', 'w+b');   // memory, then a temp file past 2 MB
        if ($stream === false) {
            throw new TransportException('Cannot open a temporary stream for the download.');
        }

        $received = [];

        try {
            [$status] = $this->execute($url, $headers, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$received): int {
                    if (preg_match('#^HTTP/\S+\s+\d{3}#', $line) === 1) {
                        $received = [];   // a new response starts (e.g. after "100 Continue")
                    } elseif (str_contains($line, ':')) {
                        [$name, $value] = explode(':', $line, 2);
                        $received[strtolower(trim($name))] = trim($value);
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static fn ($ch, string $chunk): int => (int) fwrite($stream, $chunk),
                // attachments are at most 10 MB: stop anything far bigger
                CURLOPT_MAXFILESIZE => self::MAX_DOWNLOAD_BYTES,
            ] + $this->slowTransfer());

            Protocol::checkStatus($status);
        } catch (TransportException $e) {
            fclose($stream);

            throw $e;
        }

        rewind($stream);

        return ['status' => $status, 'headers' => $received, 'stream' => $stream];
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<int, mixed>  $options
     * @return array{0: int, 1: string|bool} the status and curl_exec's result
     */
    private function execute(string $url, #[\SensitiveParameter] array $headers, array $options): array
    {
        if (! function_exists('curl_init')) {
            throw new TransportException('ext-curl is not installed.');
        }

        $ch = curl_init();
        $set = curl_setopt_array($ch, $options + [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => self::headerLines($headers),
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => $this->userAgent,
        ]);
        if (! $set) {
            throw new TransportException('The support API request could not be prepared.');
        }

        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($result === false || $status === 0) {
            throw new TransportException('Support API unreachable'.($error !== '' ? ': '.$error : '.'));
        }

        return [$status, $result];
    }

    /**
     * "Name: value" lines, with CR/LF stripped so no value can add a header.
     *
     * @param  array<string, string>  $headers
     * @return list<string>
     */
    private static function headerLines(#[\SensitiveParameter] array $headers): array
    {
        $headers += self::has($headers, 'Accept') ? [] : ['Accept' => 'application/json'];

        $lines = ['Expect:'];   // no "100-continue" pause before a multipart body
        foreach ($headers as $name => $value) {
            $lines[] = str_replace(["\r", "\n", "\0"], '', (string) $name).': '.str_replace(["\r", "\n", "\0"], '', (string) $value);
        }

        return $lines;
    }

    /** @param array<string, string> $headers */
    private static function has(array $headers, string $name): bool
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private static function without(array $headers, string $name): array
    {
        return array_filter($headers, fn ($key) => strcasecmp((string) $key, $name) !== 0, ARRAY_FILTER_USE_KEY);
    }
}
