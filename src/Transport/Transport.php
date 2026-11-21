<?php

namespace IbnKhafagah\SupportClient\Transport;

/**
 * How SupportClient reaches the CRM. CurlTransport is the default; tests use
 * an in-process one. Every implementation applies the rules in Protocol, so
 * a 5xx, a redirect or a body that is not JSON is a TransportException
 * whatever the transport.
 *
 * Headers are sent as given: SupportClient always passes Authorization
 * (Bearer), Accept (application/json) and Accept-Language.
 */
interface Transport
{
    /**
     * Sends one request and decodes the JSON answer. GET sends no body (the
     * query string is already in $url). Otherwise $json goes as a JSON body,
     * or, when $files is not empty, as multipart/form-data fields (nested
     * arrays in bracket form, see Protocol::formFields) next to the files.
     *
     * @param  array<string, string>  $headers  name => value
     * @param  array<string, mixed>  $json
     * @param  array<string, array{path: string, name: string, type: string}>  $files  keyed by the multipart field name, e.g. "attachments[0]"
     * @return array{status: int, body: array<mixed>|null} 2xx or 4xx only; body is null when empty
     *
     * @throws TransportException unreachable, timeout, 5xx, redirect, or a body that is not JSON
     */
    public function request(string $method, string $url, array $headers, array $json = [], array $files = []): array;

    /**
     * GETs a file without decoding it. The body comes back as a readable
     * stream at position 0 (the caller closes it). Header names are lower
     * case, one value each. On a 4xx the stream holds the API's JSON error.
     *
     * @param  array<string, string>  $headers  name => value
     * @return array{status: int, headers: array<string, string>, stream: resource} 2xx or 4xx only
     *
     * @throws TransportException unreachable, timeout, 5xx or redirect
     */
    public function download(string $url, array $headers): array;
}
