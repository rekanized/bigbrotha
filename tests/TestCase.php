<?php

namespace Tests;

use App\Services\AuthenticationSettingsService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected string $testStoragePath;

    protected function setUp(): void
    {
        if (is_file(dirname(__DIR__).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Refusing to run tests with cached application configuration. Clear the cache before running PHPUnit.');
        }

        if (Env::get('APP_ENV') !== 'testing'
            || Env::get('DB_CONNECTION') !== 'sqlite'
            || Env::get('DB_DATABASE') !== ':memory:'
            || filled(Env::get('DB_URL'))) {
            throw new RuntimeException(sprintf(
                'Refusing unsafe test database environment (app=%s, driver=%s, database=%s, url=%s).',
                Env::get('APP_ENV') === 'testing' ? 'testing' : 'other',
                Env::get('DB_CONNECTION') === 'sqlite' ? 'sqlite' : 'other',
                Env::get('DB_DATABASE') === ':memory:' ? 'memory' : 'other',
                filled(Env::get('DB_URL')) ? 'set' : 'empty',
            ));
        }

        parent::setUp();

        $this->testStoragePath = base_path('storage/framework/testing/'.Str::random(20));

        File::deleteDirectory($this->testStoragePath);
        File::ensureDirectoryExists($this->testStoragePath.'/app/private');
        File::ensureDirectoryExists($this->testStoragePath.'/app/private/bootstrap');
        File::ensureDirectoryExists($this->testStoragePath.'/app/public');
        File::ensureDirectoryExists($this->testStoragePath.'/app/private/continuous-recorders');
        File::ensureDirectoryExists($this->testStoragePath.'/app/private/motion-recorders');
        File::ensureDirectoryExists($this->testStoragePath.'/app/private/ffmpeg-temp');

        $this->app->useStoragePath($this->testStoragePath);

        config()->set('filesystems.disks.local.root', $this->testStoragePath.'/app/private');
        config()->set('filesystems.disks.public.root', $this->testStoragePath.'/app/public');
        config()->set('recording.continuous.runtime_dir', $this->testStoragePath.'/app/private/continuous-recorders');
        config()->set('recording.motion.runtime_dir', $this->testStoragePath.'/app/private/motion-recorders');
        config()->set('recording.health.worker_heartbeat_path', $this->testStoragePath.'/app/private/bootstrap/recordings-worker.heartbeat');
        config()->set('recording.health.scheduler_heartbeat_path', $this->testStoragePath.'/app/private/bootstrap/recordings-scheduler.heartbeat');
        config()->set('recording.health.scheduler_tick_heartbeat_path', $this->testStoragePath.'/app/private/bootstrap/recordings-tick.heartbeat');
        config()->set('ffmpeg.temporary_directory', $this->testStoragePath.'/app/private/ffmpeg-temp');

        $this->primeDefaultAuthenticationSettings();
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        $database = $app['config']->get('database');

        if ($app->environment() !== 'testing'
            || ($database['default'] ?? null) !== 'sqlite'
            || ($database['connections']['sqlite']['database'] ?? null) !== ':memory:'
            || filled($database['connections']['sqlite']['url'] ?? null)) {
            throw new RuntimeException('Refusing to run tests unless the effective database is in-memory SQLite in the testing environment.');
        }

        return $app;
    }

    protected function tearDown(): void
    {
        if (isset($this->testStoragePath)) {
            File::deleteDirectory($this->testStoragePath);
        }

        parent::tearDown();
    }

    protected function primeDefaultAuthenticationSettings(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => AuthenticationSettingsService::SETTING_SETUP_COMPLETE],
            ['value' => '1', 'updated_at' => now(), 'created_at' => now()]
        );

        DB::table('app_settings')->updateOrInsert(
            ['key' => AuthenticationSettingsService::SETTING_MANUAL_AUTH_ENABLED],
            ['value' => '1', 'updated_at' => now(), 'created_at' => now()]
        );
    }

    protected function clearAuthenticationSetupState(): void
    {
        if (! Schema::hasTable('app_settings')) {
            return;
        }

        DB::table('app_settings')->whereIn('key', [
            AuthenticationSettingsService::SETTING_SETUP_COMPLETE,
            AuthenticationSettingsService::SETTING_MANUAL_AUTH_ENABLED,
            AuthenticationSettingsService::SETTING_GOOGLE_AUTH_ENABLED,
            AuthenticationSettingsService::SETTING_GOOGLE_CLIENT_ID,
            AuthenticationSettingsService::SETTING_GOOGLE_CLIENT_SECRET,
            AuthenticationSettingsService::SETTING_GOOGLE_REDIRECT_URI,
            AuthenticationSettingsService::SETTING_GOOGLE_TESTED_FINGERPRINT,
            AuthenticationSettingsService::SETTING_GOOGLE_TESTED_AT,
            AuthenticationSettingsService::SETTING_GOOGLE_TESTED_EMAIL,
        ])->delete();
    }
}
