<?php

namespace IbnKhafagah\SupportClient;

use RuntimeException;

/**
 * A 4xx answer from the CRM: the request was understood and refused, so
 * sending it again unchanged will not help (except rate_limited, 429).
 *
 * getCode() returns the API's stable `error` code as a string (the way
 * PDOException returns its SQLSTATE), e.g. 'support_suspended'. Build on it,
 * never on the message. When the body has no `error` (a 405, or a proxy),
 * the code is the contract code for the status, else 'http_<status>'.
 */
final class ApiException extends RuntimeException
{
    /** The API's error codes (design §5.4) and their HTTP statuses. */
    public const UNAUTHORIZED = 'unauthorized';             // 401

    public const SUPPORT_SUSPENDED = 'support_suspended';   // 403, with renew_url in the body

    public const SUPPORT_REVOKED = 'support_revoked';       // 403

    public const NOT_FOUND = 'not_found';                   // 404

    public const TOO_LARGE = 'too_large';                   // 413

    public const VALIDATION_FAILED = 'validation_failed';   // 422

    public const RATE_LIMITED = 'rate_limited';             // 429

    /**
     * @param  string  $code  the API's `error`
     * @param  int  $status  the HTTP status (4xx)
     * @param  array<string, list<string>>  $errors  field => messages (validation_failed)
     * @param  array<string, mixed>  $body  the whole error body, e.g. renew_url on support_suspended
     */
    public function __construct(
        string $code,
        string $message,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly array $body = [],
    ) {
        parent::__construct($message);
        $this->code = $code;
    }

    /**
     * @param  array<mixed>|null  $body  the decoded answer
     */
    public static function fromResponse(int $status, ?array $body): self
    {
        $body ??= [];
        $error = $body['error'] ?? null;
        $message = $body['message'] ?? null;
        $errors = $body['errors'] ?? null;

        return new self(
            is_string($error) && $error !== '' ? $error : self::codeFor($status),
            is_string($message) && $message !== '' ? $message : "The support API refused the request (HTTP {$status}).",
            $status,
            is_array($errors) ? $errors : [],
            $body,
        );
    }

    private static function codeFor(int $status): string
    {
        return match ($status) {
            401 => self::UNAUTHORIZED,
            404 => self::NOT_FOUND,
            413 => self::TOO_LARGE,
            422 => self::VALIDATION_FAILED,
            429 => self::RATE_LIMITED,
            default => 'http_'.$status,
        };
    }
}
