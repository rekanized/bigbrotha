<?php

use App\Jobs\RefreshCameraPreviewJob;
use App\Models\Camera;
use App\Services\Relay\MediaMtxInstaller;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('relay:install {--force}', function (): int {
    $binaryPath = app(MediaMtxInstaller::class)->install((bool) $this->option('force'));

    $this->components->info('MediaMTX installed at '.$binaryPath);

    return 0;
})->purpose('Download and install the MediaMTX WebRTC relay');

Artisan::command('relay:sync', function (): int {
    $changed = app(MediaMtxProcessService::class)->syncConfig();

    $this->components->info($changed ? 'MediaMTX configuration updated.' : 'MediaMTX configuration already current.');

    return 0;
})->purpose('Generate the MediaMTX relay configuration from enabled cameras');

Artisan::command('relay:start', function (): int {
    $status = app(MediaMtxProcessService::class)->start();

    $this->table(['Installed', 'Running', 'API reachable', 'PID'], [[
        $status['installed'] ? 'yes' : 'no',
        $status['running'] ? 'yes' : 'no',
        $status['api_reachable'] ? 'yes' : 'no',
        $status['pid'] ?? 'n/a',
    ]]);

    return 0;
})->purpose('Start the MediaMTX relay process');

Artisan::command('relay:stop', function (): int {
    app(MediaMtxProcessService::class)->stop();

    $this->components->info('MediaMTX relay stopped.');

    return 0;
})->purpose('Stop the MediaMTX relay process');

Artisan::command('relay:status', function (): int {
    $status = app(MediaMtxProcessService::class)->status();

    $this->table(['Installed', 'Running', 'API reachable', 'PID', 'Binary', 'Config', 'Log'], [[
        $status['installed'] ? 'yes' : 'no',
        $status['running'] ? 'yes' : 'no',
        $status['api_reachable'] ? 'yes' : 'no',
        $status['pid'] ?? 'n/a',
        $status['binary_path'],
        $status['config_path'],
        $status['log_path'],
    ]]);

    return 0;
})->purpose('Show the MediaMTX relay status');

Artisan::command('camera-fleet:refresh-previews', function (): int {
    $refreshed = 0;

    Camera::query()
        ->where('is_enabled', true)
        ->where('supports_rtsp', true)
        ->orderBy('id')
        ->chunkById(50, function ($cameras) use (&$refreshed): void {
            foreach ($cameras as $camera) {
                if ($camera->rtspPreviewRefreshTarget() === null) {
                    continue;
                }

                RefreshCameraPreviewJob::dispatchSync($camera->id);
                $refreshed++;
            }
        });

    $this->components->info('Refreshed preview snapshots for '.$refreshed.' camera'.($refreshed === 1 ? '' : 's').'.');

    return 0;
})->purpose('Refresh saved RTSP preview snapshots for eligible cameras');

Schedule::command('camera-fleet:refresh-previews')
    ->everyThirtyMinutes()
    ->withoutOverlapping();
