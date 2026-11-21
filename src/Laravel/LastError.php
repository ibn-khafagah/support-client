<?php

namespace IbnKhafagah\SupportClient\Laravel;

use DateTimeImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * The last errors the user hit (up to three), kept in their session so the
 * "Report this problem" button and the next ticket can carry one: a short
 * reference (E- and 4 hex digits) shown on the error page, the exception
 * class, the message (secrets and request data masked, cut to 300
 * characters) and the time.
 *
 * Never the trace, the file or line, a SQL statement, or anything from the
 * request.
 *
 * Each error belongs to the user who was logged in when it happened: after
 * a logout that keeps the session, the next user does not see it. Without
 * a started session (console, queue, a stateless API route) nothing is
 * kept. The reference is also added to the log context
 * (`support_error_ref`), so the host's log line for the error carries it.
 */
final class LastError
{
    public const SESSION_KEY = 'support_client_last_error';

    public const MESSAGE_LENGTH = 300;

    public const REF_PATTERN = '/^E-[0-9A-F]{4}$/';

    /** How many errors are kept per session, newest first. */
    public const KEEP = 3;

    /** A ticket opened without a reference carries the newest error only if it is this recent. */
    public const MAX_AGE_MINUTES = 120;

    /** Marks the reference remembered during the current request. */
    private const REQUEST_ATTRIBUTE = 'support_client_error_ref';

    /**
     * Keeps $e as the session's newest error and returns its reference.
     */
    public static function remember(Throwable $e): string
    {
        $ref = 'E-'.strtoupper(bin2hex(random_bytes(2)));

        $request = self::request();
        $session = self::session($request);
        if ($session === null) {
            return $ref;
        }

        $errors = array_slice(self::stored($session), 0, self::KEEP - 1);
        array_unshift($errors, [
            'ref' => $ref,
            'class' => self::className($e),
            'message' => self::message($e),
            'at' => now()->format(DATE_ATOM),
            'user' => self::userId(),
        ]);
        $session->put(self::SESSION_KEY, $errors);
        $request?->attributes->set(self::REQUEST_ATTRIBUTE, $ref);

        try {
            if (class_exists(Context::class)) {
                Context::add('support_error_ref', $ref);
            }
        } catch (Throwable) {
            // the log context is a convenience
        }

        return $ref;
    }

    /**
     * The current user's newest error, if it happened in the last
     * MAX_AGE_MINUTES.
     *
     * @return array{ref: string, class: string, message: string, at: string}|null
     */
    public static function get(): ?array
    {
        $error = self::mine()[0] ?? null;
        if ($error === null) {
            return null;
        }

        $at = DateTimeImmutable::createFromFormat(DATE_ATOM, $error['at']);
        if ($at === false || $at < now()->subMinutes(self::MAX_AGE_MINUTES)) {
            return null;
        }

        return $error;
    }

    /**
     * One of the current user's kept errors by its reference (the one the
     * "Report this problem" button was shown for), whatever its age.
     *
     * @return array{ref: string, class: string, message: string, at: string}|null
     */
    public static function find(?string $ref): ?array
    {
        if (! is_string($ref) || ! preg_match(self::REF_PATTERN, $ref)) {
            return null;
        }

        foreach (self::mine() as $error) {
            if ($error['ref'] === $ref) {
                return $error;
            }
        }

        return null;
    }

    /**
     * The error remembered during the current request, if any: what an
     * error page shows (an older error in the session is not this one).
     *
     * @return array{ref: string, class: string, message: string, at: string}|null
     */
    public static function current(): ?array
    {
        $ref = self::request()?->attributes->get(self::REQUEST_ATTRIBUTE);

        return is_string($ref) ? self::find($ref) : null;
    }

    public static function forget(): void
    {
        self::session(self::request())?->forget(self::SESSION_KEY);
    }

    /**
     * The current user's errors, newest first, as the public methods return them.
     *
     * @return list<array{ref: string, class: string, message: string, at: string}>
     */
    private static function mine(): array
    {
        $session = self::session(self::request());
        if ($session === null) {
            return [];
        }

        $user = self::userId();
        $errors = [];
        foreach (self::stored($session) as $error) {
            if ($error['user'] === $user) {
                unset($error['user']);
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * The well-formed entries in the session, newest first.
     *
     * @return list<array{ref: string, class: string, message: string, at: string, user: string|null}>
     */
    private static function stored(Session $session): array
    {
        $value = $session->get(self::SESSION_KEY);
        if (! is_array($value)) {
            return [];
        }

        $errors = [];
        foreach (array_values($value) as $error) {
            if (! is_array($error) || ! is_string($error['ref'] ?? null) || ! preg_match(self::REF_PATTERN, $error['ref'])) {
                continue;
            }
            foreach (['class', 'message', 'at'] as $key) {
                if (! is_string($error[$key] ?? null)) {
                    continue 2;
                }
            }
            $user = $error['user'] ?? null;

            $errors[] = [
                'ref' => $error['ref'],
                'class' => $error['class'],
                'message' => $error['message'],
                'at' => $error['at'],
                'user' => is_string($user) ? $user : null,
            ];
        }

        return array_slice($errors, 0, self::KEEP);
    }

    /** The message as a ticket may carry it: secrets and request data masked, paths hidden, at most 300 characters. */
    private static function message(Throwable $e): string
    {
        $basePath = '';
        $known = [];
        try {
            $basePath = (string) app()->basePath();
            // this app's own support key and webhook secret, whatever form they take in the message
            $known = [config('support-client.key'), config('support-client.webhook_secret')];
        } catch (Throwable) {
            // outside an application: no app path to hide
        }

        $message = Redactor::scrub($e->getMessage(), $basePath, $known);
        if ($message === '' || $message === '(SQL hidden)') {
            $message = trim(self::shortName(self::className($e)).' '.$message);
        }

        return mb_strlen($message) > self::MESSAGE_LENGTH ? mb_substr($message, 0, self::MESSAGE_LENGTH - 1).'…' : $message;
    }

    /** An anonymous class's name carries its file path: only "class@anonymous" is kept. */
    private static function className(Throwable $e): string
    {
        $class = get_class($e);
        $at = strpos($class, '@anonymous');

        return mb_substr($at === false ? $class : substr($class, 0, $at + strlen('@anonymous')), 0, 255);
    }

    private static function shortName(string $class): string
    {
        $slash = strrpos($class, '\\');

        return $slash === false ? $class : substr($class, $slash + 1);
    }

    /** The logged-in user's id (default guard) as text, or null for a guest. */
    private static function userId(): ?string
    {
        try {
            $id = auth()->id();
        } catch (Throwable) {
            return null;
        }

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    private static function request(): ?Request
    {
        try {
            $app = app();
            $request = $app->bound('request') ? $app->make('request') : null;

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The request's session, if it has one and it is started (it is saved at the end of the request). */
    private static function session(?Request $request): ?Session
    {
        try {
            if ($request === null || ! $request->hasSession()) {
                return null;
            }

            $session = $request->session();

            return $session->isStarted() ? $session : null;
        } catch (Throwable) {
            return null;
        }
    }
}
