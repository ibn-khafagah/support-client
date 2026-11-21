<?php

use IbnKhafagah\SupportClient\Laravel\DefaultSupportUserResolver;

return [

    /*
    | The CRM host only, without /api/v1/support (the client adds it), e.g.
    | https://crm.ibnkhafagah.com. The key and the webhook secret come from
    | the provider and stay on this server: they are never sent to a browser.
    */
    'url' => env('SUPPORT_API_URL'),

    'key' => env('SUPPORT_API_KEY'),

    /*
    | Verifies the CRM's notifications to POST /support/webhook. Without it,
    | changes are picked up by `support:sync` every 5 minutes; with it, the
    | sync still runs every 30 minutes to catch any notification missed.
    */
    'webhook_secret' => env('SUPPORT_WEBHOOK_SECRET'),

    /*
    | The support screens: GET /support, /support/create, /support/{number}, ...
    | (route names support-client.*). Keep `auth` (or your own guard): every
    | route needs the logged-in user. The throttle keeps one user from using
    | up the system's request limit at the CRM (120 a minute for the key).
    | An empty prefix falls back to `support`.
    |
    | POST /support/webhook (the CRM's signed notifications) is outside this
    | middleware: no session, no login and no CSRF token; the signature is
    | its check.
    */
    'route_prefix' => 'support',

    'middleware' => ['web', 'auth', 'throttle:60,1'],

    /*
    | Other hosts of this same app (e.g. ['www.example.com', 'app.example.com'])
    | accepted in the page URL a ticket reports. The host of APP_URL is always
    | accepted; a page on any other host is dropped from the ticket.
    */
    'hosts' => [],

    /*
    | Turns the logged-in user into the ticket's `reporter`. A class that
    | implements IbnKhafagah\SupportClient\Laravel\Contracts\ResolvesSupportUser.
    | The default sends the user's id, `name` and `email`; write your own to
    | add the phone and the role.
    */
    'user_resolver' => DefaultSupportUserResolver::class,

    /*
    | The gate (defined in your app) that means "support admin": sees and
    | answers every ticket of this system. Everyone else sees only their own
    | tickets; another user's ticket is a 404. Empty: nobody is an admin.
    */
    'admin_gate' => 'support-admin',

    // sent with each ticket, so the support team knows which app and version it came from
    'app_name' => env('SUPPORT_APP_NAME', env('APP_NAME')),

    'app_version' => env('SUPPORT_APP_VERSION', env('APP_VERSION')),

];
