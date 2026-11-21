<?php

namespace IbnKhafagah\SupportClient\Laravel\Contracts;

use IbnKhafagah\SupportClient\Laravel\ReporterData;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Turns the host app's logged-in user into the ticket's reporter. Set the
 * class in config('support-client.user_resolver'). The id must be the same
 * every time for the same user: it decides which tickets they see.
 */
interface ResolvesSupportUser
{
    public function resolve(Authenticatable $user): ReporterData;
}
