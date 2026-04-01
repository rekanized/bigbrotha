<?php

namespace App\Providers;

use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FFProbe::class, function ($app) {
            $config = $app['config']->get('ffmpeg');

            File::ensureDirectoryExists($config['temporary_directory']);

            return FFProbe::create([
                'ffprobe.binaries' => $config['ffprobe']['binaries'],
                'timeout' => $config['timeout'],
            ]);
        });

        $this->app->singleton(FFMpeg::class, function ($app) {
            $config = $app['config']->get('ffmpeg');

            File::ensureDirectoryExists($config['temporary_directory']);

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
        //
    }
}
