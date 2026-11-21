<?php

use IbnKhafagah\SupportClient\Laravel\Http\SupportController;
use IbnKhafagah\SupportClient\Laravel\Http\WebhookController;
use Illuminate\Support\Facades\Route;

/*
| The support screens: a server-side proxy to the CRM, so the key never
| leaves this server. Every route answers JSON when the request wants it.
| Fixed paths (webhook, create, unread, and any added later) go before
| /{number}; a ticket number must also contain a digit, so /{number} never
| catches them, nor a host's own pages under the same prefix (/support/faq).
*/
$prefix = trim((string) config('support-client.route_prefix', 'support'), '/');
if ($prefix === '') {
    $prefix = 'support';
}

// the CRM's signed notifications: no session, no login, no CSRF token (the signature is the check)
Route::prefix($prefix)
    ->name('support-client.')
    ->group(function () {
        Route::post('/webhook', WebhookController::class)->name('webhook');
    });

Route::middleware((array) config('support-client.middleware', ['web', 'auth', 'throttle:60,1']))
    ->prefix($prefix)
    ->name('support-client.')
    ->group(function () {
        // a ticket number such as TCK-00042: letters, digits, _ and -, with at least one digit
        $number = '(?=[A-Za-z0-9_-]*[0-9])[A-Za-z0-9][A-Za-z0-9_-]{0,63}';

        Route::get('/', [SupportController::class, 'index'])->name('index');
        Route::get('/create', [SupportController::class, 'create'])->name('create');
        Route::get('/unread', [SupportController::class, 'unread'])->name('unread');
        Route::post('/', [SupportController::class, 'store'])->name('store');

        Route::get('/{number}', [SupportController::class, 'show'])->where('number', $number)->name('show');
        Route::post('/{number}/replies', [SupportController::class, 'reply'])->where('number', $number)->name('reply');
        Route::post('/{number}/close', [SupportController::class, 'close'])->where('number', $number)->name('close');
        Route::get('/{number}/attachments/{id}', [SupportController::class, 'attachment'])->where(['number' => $number, 'id' => '[0-9]{1,18}'])->name('attachment');
    });
