<?php

use App\Services\Relay\MediaMtxInstaller;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
