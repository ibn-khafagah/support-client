<?php

namespace IbnKhafagah\SupportClient\Tests;

use IbnKhafagah\SupportClient\Laravel\Outbox;
use IbnKhafagah\SupportClient\Laravel\SupportClientServiceProvider;
use IbnKhafagah\SupportClient\Laravel\SyncCursor;
use IbnKhafagah\SupportClient\Tests\Support\FakeCrm;
use IbnKhafagah\SupportClient\Transport\Transport;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A Laravel app with the package, an in-memory CRM (no network), temporary outbox / cursor folders and two users:
 * Sara (id 7, regular) and Omar (id 1, support admin through the `support-admin` gate).
 */
abstract class TestCase extends Orchestra
{
    public const KEY = 'cs_TestKeyThatMustNeverLeaveTheServer0123456';

    public const SECRET = 'whsec_test_secret_0123456789abcdef0123456789abcdef';

    protected FakeCrm $crm;

    protected string $dir;

    protected function getPackageProviders($app): array
    {
        return [SupportClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://erp.example.com');
        $app['config']->set('app.name', 'Example ERP');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('session.driver', 'array');
        $app['config']->set('support-client.url', 'https://crm.example.com');
        $app['config']->set('support-client.key', self::KEY);
        $app['config']->set('support-client.webhook_secret', null);
        $app['config']->set('support-client.app_name', 'Example ERP');
        $app['config']->set('support-client.app_version', '2.4.0');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/support-client-tests-'.uniqid();
        File::ensureDirectoryExists($this->dir);
        $this->crm = FakeCrm::withDefaults();
        $this->app->instance(Transport::class, $this->crm);
        $this->app->instance(Outbox::class, new Outbox($this->dir.'/outbox'));
        $this->app->instance(SyncCursor::class, new SyncCursor($this->dir.'/cursor.json'));

        Gate::define('support-admin', fn ($user): bool => (int) $user->getAuthIdentifier() === 1);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    protected function sara(): GenericUser
    {
        return new GenericUser(['id' => 7, 'name' => 'Sara Ali', 'email' => 'sara@example.com', 'remember_token' => null]);
    }

    protected function omar(): GenericUser
    {
        return new GenericUser(['id' => 1, 'name' => 'Omar Admin', 'email' => 'omar@example.com', 'remember_token' => null]);
    }
}
