<?php

namespace IbnKhafagah\SupportClient\Transport;

use JsonException;

/**
 * The wire rules every Transport follows, kept in one place so a test
 * transport behaves exactly like CurlTransport:
 *
 * - only 2xx and 4xx are answers from the API. No answer, 1xx, 3xx (the
 *   package never follows redirects) and 5xx are a TransportException;
 * - an answer body must be a JSON object or array (empty is allowed and
 *   decodes to null); anything else, such as a proxy's HTML page, is a
 *   TransportException;
 * - multipart fields are the JSON body flattened to PHP bracket names.
 */
final class Protocol
{
    /**
     * @throws TransportException unless $status is 2xx or 4xx
     */
    public static function checkStatus(int $status): void
    {
        if ($status === 0) {
            throw new TransportException('No answer from the support API.');
        }
        if ($status >= 500) {
            throw new TransportException("The support API failed (HTTP {$status}).");
        }
        if ($status < 200 || ($status >= 300 && $status < 400)) {
            throw new TransportException("Unexpected HTTP {$status} from the support API; check the CRM URL.");
        }
    }

    /**
     * @return array<mixed>|null the decoded body, null when empty
     *
     * @throws TransportException for a status that is not 2xx/4xx, or a body that is not JSON
     */
    public static function decode(int $status, string $raw): ?array
    {
        self::checkStatus($status);

        if (trim($raw) === '') {
            return null;
        }

        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $body = null;
        }

        if (! is_array($body)) {
            // a web server or firewall page (Apache's 413, a ModSecurity 403) is a final answer for this
            // request: retrying it forever would never help. ApiException codes it from the status.
            // 408 (timeout) is the one 4xx worth another try.
            if ($status >= 400 && $status !== 408) {
                return ['message' => "HTTP {$status} from the web server in front of the support API."];
            }

            throw new TransportException("The support API answered HTTP {$status} with a body that is not JSON.");
        }

        return $body;
    }

    /**
     * Flattens a body for multipart/form-data: ['reporter' => ['id' => 17]]
     * becomes ['reporter[id]' => '17']. Every value is text, as in any
     * multipart request; true/false become '1'/'0' and nulls are left out.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>
     */
    public static function formFields(array $data, string $prefix = ''): array
    {
        $fields = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'['.$key.']';

            if (is_array($value)) {
                $fields += self::formFields($value, $name);
            } elseif (is_bool($value)) {
                $fields[$name] = $value ? '1' : '0';
            } elseif (is_scalar($value) || $value instanceof \Stringable) {
                $fields[$name] = (string) $value;
            }
        }

        return $fields;
    }
}
