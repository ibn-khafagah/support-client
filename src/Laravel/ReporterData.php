<?php

namespace IbnKhafagah\SupportClient\Laravel;

/**
 * The person inside the host app who opens or answers a ticket: the API's
 * `reporter` object. `id` is the host's own user id (as text); it decides
 * whose tickets a user sees. Email, phone and role are optional: when null
 * they are left out of the request.
 *
 * Every value is trimmed the way Laravel's TrimStrings middleware trims
 * input (Unicode spaces, and the zero-width space, BOM and left-to-right
 * mark), so an id never differs from the one the CRM stores by an
 * invisible character.
 */
final class ReporterData
{
    /** The CRM's limits (reporter.id, reporter.name, reporter.email). */
    public const MAX_ID = 100;

    public const MAX_NAME = 150;

    public const MAX_EMAIL = 190;

    public readonly string $id;

    public readonly string $name;

    public function __construct(
        string $id,
        string $name,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $role = null,
    ) {
        $this->id = self::trim($id);
        $this->name = self::trim($name);
    }

    /**
     * Whether this id can stand for one user: not empty, and short enough
     * for the CRM to keep whole. A longer id would be cut there, and two
     * users whose ids start alike would then share tickets, so the package
     * refuses it (403) instead.
     */
    public function hasUsableId(): bool
    {
        return $this->id !== '' && mb_strlen($this->id) <= self::MAX_ID;
    }

    /**
     * The `reporter` object for the API: empty values left out. The id goes
     * as it is (hasUsableId() is checked first); the name, phone and role
     * are cut to the API's limits; an email that is too long or not a valid
     * address is dropped, never cut (the user could not fix the API's 422
     * on it, and a cut address would reach someone else).
     *
     * @return array{id: string, name: string, email?: string, phone?: string, role?: string}
     */
    public function toArray(): array
    {
        $email = self::clean($this->email);
        if ($email !== null && (mb_strlen($email) > self::MAX_EMAIL || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $email = null;
        }

        return array_filter([
            'id' => $this->id,
            // the API needs a name; a user without one is shown by their id
            'name' => self::clean($this->name, self::MAX_NAME) ?? mb_substr('#'.$this->id, 0, self::MAX_NAME),
            'email' => $email,
            'phone' => self::clean($this->phone, 50),
            'role' => self::clean($this->role, 100),
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    /** The trimmed value, cut to $max characters when given; null when empty. */
    private static function clean(?string $value, ?int $max = null): ?string
    {
        $value = self::trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $max === null ? $value : mb_substr($value, 0, $max);
    }

    /** As Laravel's TrimStrings (Str::trim): Unicode spaces, U+FEFF, U+200B and U+200E at either end. */
    private static function trim(string $value): string
    {
        return preg_replace('~^[\s\x{FEFF}\x{200B}\x{200E}]+|[\s\x{FEFF}\x{200B}\x{200E}]+$~u', '', $value) ?? trim($value);
    }
}
