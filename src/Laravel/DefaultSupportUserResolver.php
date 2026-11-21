<?php

namespace IbnKhafagah\SupportClient\Laravel;

use IbnKhafagah\SupportClient\Laravel\Contracts\ResolvesSupportUser;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default reporter: the user's auth identifier, `name` and `email`
 * (each may be missing). No phone and no role: write your own resolver to
 * send them.
 */
class DefaultSupportUserResolver implements ResolvesSupportUser
{
    public function resolve(Authenticatable $user): ReporterData
    {
        $name = self::attribute($user, 'name');
        $email = self::attribute($user, 'email');

        return new ReporterData(
            id: (string) $user->getAuthIdentifier(),
            name: $name ?? $email ?? '',
            email: $email,
        );
    }

    private static function attribute(Authenticatable $user, string $key): ?string
    {
        $value = $user->{$key} ?? null;

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
