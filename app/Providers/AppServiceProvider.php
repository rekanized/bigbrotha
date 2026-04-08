<?php

namespace App\Providers;

use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use App\Services\ApplicationSettingsService;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        $this->normalizeSharedRuntimePaths();

        $settings = $this->app->make(ApplicationSettingsService::class);

        $settings->apply();
        View::share('appSettings', $settings);
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

        if (is_file($path) && !is_writable($path) && is_dir($directory) && is_writable($directory)) {
            @rename($path, $path.'.locked-'.date('YmdHis'));
            clearstatcache(true, $path);
        }

        if (!is_file($path) && is_dir($directory) && is_writable($directory)) {
            $handle = @fopen($path, 'ab');

            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (is_file($path)) {
            @chmod($path, 0664);
        }
    }
}
