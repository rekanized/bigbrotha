<?php

namespace App\Providers;

use App\Http\Middleware\EnsureAdminUser;
use App\Services\ApplicationSettingsService;
use App\Services\ApplicationSettingStore;
use App\Services\AuthenticationSettingsService;
use App\Services\Relay\MediaMtxPathStatusService;
use App\Services\RuntimeHeartbeatService;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ApplicationSettingStore::class, static fn (): ApplicationSettingStore => new ApplicationSettingStore);
        $this->app->scoped(MediaMtxPathStatusService::class, static fn (): MediaMtxPathStatusService => new MediaMtxPathStatusService);

        $this->app->singleton(FFProbe::class, function ($app) {
            $config = $app['config']->get('ffmpeg');

            $this->normalizeSharedDirectory((string) $config['temporary_directory']);

            return FFProbe::create([
                'ffprobe.binaries' => $config['ffprobe']['binaries'],
                'timeout' => $config['timeout'],
            ]);
        });

        $this->app->singleton(FFMpeg::class, function ($app) {
            $config = $app['config']->get('ffmpeg');

            $this->normalizeSharedDirectory((string) $config['temporary_directory']);

            return FFMpeg::create([
                'ffmpeg.binaries' => $config['ffmpeg']['binaries'],
                'ffprobe.binaries' => $config['ffprobe']['binaries'],
                'timeout' => $config['timeout'],
                'ffmpeg.threads' => $config['ffmpeg']['threads'],
                'temporary_directory' => $config['temporary_directory'],
            ], probe: $app->make(FFProbe::class));
        });

        $this->app->alias(FFMpeg::class, 'ffmpeg');
        $this->app->alias(FFProbe::class, 'ffprobe');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->normalizeSharedRuntimePaths();
        }

        // Public URLs come from deployment configuration, never forwarding headers.
        $publicUrl = (string) config('app.url');
        if (parse_url($publicUrl, PHP_URL_SCHEME) === 'https') {
            URL::useOrigin($publicUrl);
            URL::forceScheme('https');
        }

        Livewire::addPersistentMiddleware([EnsureAdminUser::class]);

        $this->registerWorkerHeartbeatHooks();

        $settings = $this->app->make(ApplicationSettingsService::class);
        $authSettings = $this->app->make(AuthenticationSettingsService::class);

        $settings->apply();
        $authSettings->apply();
        View::share('appSettings', $settings);
        View::share('authSettings', $authSettings);
    }

    private function normalizeSharedRuntimePaths(): void
    {
        $ffmpegTempDirectory = trim((string) config('ffmpeg.temporary_directory', storage_path('app/private/ffmpeg-temp')));

        $directories = array_filter([
            storage_path('logs'),
            $ffmpegTempDirectory,
            $ffmpegTempDirectory !== '' ? rtrim($ffmpegTempDirectory, '/').'/recording-playback' : null,
            $ffmpegTempDirectory !== '' ? rtrim($ffmpegTempDirectory, '/').'/motion-recordings' : null,
            trim((string) config('recording.motion.runtime_dir', storage_path('app/private/motion-recorders'))),
            trim((string) config('recording.continuous.runtime_dir', storage_path('app/private/continuous-recorders'))),
        ]);

        foreach ($directories as $directory) {
            $this->normalizeSharedDirectory((string) $directory);
        }

        $this->normalizeSharedLogFile((string) config('logging.channels.single.path', storage_path('logs/laravel.log')));
    }

    private function normalizeSharedDirectory(string $directory): void
    {
        $directory = trim($directory);

        if ($directory === '') {
            return;
        }

        try {
            File::ensureDirectoryExists($directory);
            @chmod($directory, 02775);
        } catch (Throwable) {
        }
    }

    private function normalizeSharedLogFile(string $path): void
    {
        $path = trim($path);

        if ($path === '') {
            return;
        }

        $directory = dirname($path);

        $this->normalizeSharedDirectory($directory);

        clearstatcache(true, $path);

        if (is_file($path) && is_writable($path)) {
            @chmod($path, 0664);

            return;
        }

        if (is_file($path) && ! is_writable($path) && is_dir($directory) && is_writable($directory)) {
            @rename($path, $path.'.locked-'.date('YmdHis'));
            clearstatcache(true, $path);
        }

        if (! is_file($path) && is_dir($directory) && is_writable($directory)) {
            $handle = @fopen($path, 'ab');

            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (is_file($path)) {
            @chmod($path, 0664);
        }
    }

    private function registerWorkerHeartbeatHooks(): void
    {
        if (! $this->isRecordingWorkerConsoleProcess()) {
            return;
        }

        $heartbeats = $this->app->make(RuntimeHeartbeatService::class);
        $touch = static function (string $context) use ($heartbeats): void {
            try {
                $heartbeats->touchWorker($context);
            } catch (Throwable) {
            }
        };

        $touch('boot');

        Queue::looping(static function () use ($touch): void {
            $touch('looping');
        });

        Queue::before(static function (JobProcessing $event) use ($touch): void {
            $touch('before:'.$event->job->resolveName());
        });

        Queue::after(static function (JobProcessed $event) use ($touch): void {
            $touch('after:'.$event->job->resolveName());
        });

        Queue::exceptionOccurred(static function (JobExceptionOccurred $event) use ($touch): void {
            $touch('exception:'.$event->job->resolveName());
        });

        Queue::failing(static function (JobFailed $event) use ($touch): void {
            $touch('failed:'.$event->job->resolveName());
        });
    }

    private function isRecordingWorkerConsoleProcess(): bool
    {
        if (PHP_SAPI !== 'cli') {
            return false;
        }

        $args = array_values(array_filter($_SERVER['argv'] ?? [], 'is_string'));

        return in_array('queue:work', $args, true);
    }
}
