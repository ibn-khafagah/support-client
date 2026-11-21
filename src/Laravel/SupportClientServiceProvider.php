<?php

namespace IbnKhafagah\SupportClient\Laravel;

use IbnKhafagah\SupportClient\Laravel\Console\FlushCommand;
use IbnKhafagah\SupportClient\Laravel\Console\SyncCommand;
use IbnKhafagah\SupportClient\Laravel\Contracts\ResolvesSupportUser;
use IbnKhafagah\SupportClient\Laravel\View\Badge;
use IbnKhafagah\SupportClient\Laravel\View\ReportButton;
use IbnKhafagah\SupportClient\SupportClient;
use IbnKhafagah\SupportClient\Transport\CurlTransport;
use IbnKhafagah\SupportClient\Transport\Transport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Compilers\BladeCompiler;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Auto-discovered. Adds:
 *   - config('support-client'), from SUPPORT_API_URL, SUPPORT_API_KEY and SUPPORT_WEBHOOK_SECRET;
 *   - the support screens under config('support-client.route_prefix') (route names
 *     support-client.*), each answering JSON when the request wants JSON;
 *   - the views (support-client::) and translations (support-client::support.*);
 *   - <x-support-report-button /> for the host's errors/500.blade.php, and
 *     <x-support-badge /> (the unread count) for its navigation;
 *   - a `reportable` callback on the host's exception handler that keeps the
 *     user's last error in their session (LastError), next to the host's own
 *     reporting, which it never stops;
 *   - POST {prefix}/webhook for the CRM's signed notifications, and the
 *     `support:sync` command on the host's scheduler: every 5 minutes without
 *     a webhook secret, every 30 minutes (a catch-up) with one;
 *   - the outbox (storage/app/support-client/outbox) for tickets opened while
 *     the CRM can't be reached, and `support:flush` every minute to send them;
 *   - `php artisan vendor:publish --provider=...` for the config, views and translations.
 *
 * SupportClient is bound, not shared: each resolution sends the current app
 * locale as Accept-Language, so category names follow the user's language.
 * Bind your own Transport before this provider registers to replace curl,
 * and your own ContextCollector subclass to send more automatic details.
 */
class SupportClientServiceProvider extends ServiceProvider
{
    private const ROOT = __DIR__.'/../..';

    /**
     * The user's own mistakes, not errors of the app: never the last error.
     * (Laravel does not report these by default; this holds even if the
     * host reports them.) Any HTTP exception below 500 is skipped too.
     */
    private const NOT_ERRORS = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        TokenMismatchException::class,
        ModelNotFoundException::class,
        RecordsNotFoundException::class,
        HttpResponseException::class,
        RequestExceptionInterface::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(self::ROOT.'/config/support-client.php', 'support-client');

        $this->app->singletonIf(Transport::class, fn () => new CurlTransport);

        $this->app->bind(ResolvesSupportUser::class, function (Application $app) {
            return $app->make($app['config']->get('support-client.user_resolver') ?: DefaultSupportUserResolver::class);
        });

        $this->app->bind(SupportClient::class, function (Application $app) {
            $config = $app['config'];

            return new SupportClient(
                (string) $config->get('support-client.url'),
                (string) $config->get('support-client.key'),
                $app->make(Transport::class),
                $app->getLocale(),
            );
        });

        $this->app->bindIf(ContextCollector::class);
        $this->app->bindIf(Unread::class);
        $this->app->bindIf(StatusCache::class);
        $this->app->bindIf(SupportEventHandler::class);
        $this->app->bindIf(SyncCursor::class, fn (Application $app) => new SyncCursor($app->storagePath('app/support-client/cursor.json')));
        $this->app->bindIf(Outbox::class, fn (Application $app) => new Outbox($app->storagePath('app/support-client/outbox')));
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes.php');
        $this->loadViewsFrom(self::ROOT.'/resources/views', 'support-client');
        $this->loadTranslationsFrom(self::ROOT.'/resources/lang', 'support-client');

        $this->callAfterResolving(BladeCompiler::class, function (BladeCompiler $blade) {
            $blade->component(ReportButton::class, 'support-report-button');
            $blade->component(Badge::class, 'support-badge');
        });

        $this->callAfterResolving(ExceptionHandler::class, function (mixed $handler) {
            if (is_object($handler) && method_exists($handler, 'reportable')) {
                // returns nothing (never false), so the host's own reporting goes on
                $handler->reportable(function (Throwable $e): void {
                    self::rememberError($e);
                });
            }
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([self::ROOT.'/config/support-client.php' => $this->app->configPath('support-client.php')], ['support-client', 'support-client-config']);
            $this->publishes([self::ROOT.'/resources/views' => $this->app->resourcePath('views/vendor/support-client')], ['support-client', 'support-client-views']);
            $this->publishes([self::ROOT.'/resources/lang' => $this->app->langPath('vendor/support-client')], ['support-client', 'support-client-lang']);

            $this->commands([SyncCommand::class, FlushCommand::class]);

            $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
                $this->scheduleSync($schedule);
                $this->scheduleFlush($schedule);
            });
        }
    }

    /**
     * `support:flush` on the host's scheduler, every minute: the tickets
     * saved while the CRM could not be reached go out soon after it is back.
     */
    public function scheduleFlush(Schedule $schedule): void
    {
        $schedule->command('support:flush')->everyMinute()->withoutOverlapping(10);
    }

    /**
     * `support:sync` on the host's scheduler: every 5 minutes when there is
     * no webhook secret (the change log is the only source), otherwise every
     * 30 minutes to catch any notification that was missed.
     */
    public function scheduleSync(Schedule $schedule): void
    {
        $event = $schedule->command('support:sync')->withoutOverlapping(15);

        filled($this->app['config']->get('support-client.webhook_secret'))
            ? $event->everyThirtyMinutes()
            : $event->everyFiveMinutes();
    }

    /**
     * Keeps a real error (not a 4xx, a failed validation or a missing login)
     * as the user's last error. Never throws: it runs inside the host's
     * exception reporting.
     */
    private static function rememberError(Throwable $e): void
    {
        try {
            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return;
            }
            foreach (self::NOT_ERRORS as $type) {
                if ($e instanceof $type) {
                    return;
                }
            }

            LastError::remember($e);
        } catch (Throwable) {
            // the host's error reporting must go on whatever happens here
        }
    }
}
