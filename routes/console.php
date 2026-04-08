<?php

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Jobs\RefreshCameraPreviewJob;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\CameraRecordingService;
use App\Services\CameraStorageService;
use App\Services\RecordingWorkerService;
use App\Services\RecordingReviewAssetService;
use App\Services\ContinuousRecordingSegmenterService;
use App\Services\MotionRecordingSegmenterService;
use App\Services\Relay\MediaMtxInstaller;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('db:import-sqlite {path=database/database.sqlite}', function (): int {
    $sourcePathArgument = (string) $this->argument('path');
    $sourcePath = str_starts_with($sourcePathArgument, DIRECTORY_SEPARATOR)
        ? $sourcePathArgument
        : base_path($sourcePathArgument);

    if (!is_file($sourcePath)) {
        $this->components->error('SQLite source file not found: '.$sourcePath);

        return 1;
    }

    $target = DB::connection();

    if ($target->getDriverName() !== 'pgsql') {
        $this->components->error('The active database connection must be pgsql to import SQLite data.');

        return 1;
    }

    $sqlite = new PDO('sqlite:'.$sourcePath);
    $sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $tables = [
        'cache',
        'cache_locks',
        'password_reset_tokens',
        'users',
        'sessions',
        'app_settings',
        'cameras',
        'allowed_login_emails',
        'live_walls',
        'live_wall_tiles',
        'jobs',
        'job_batches',
        'failed_jobs',
        'camera_recordings',
    ];

    $sourceTables = [];

    foreach ($sqlite->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $row) {
        $name = is_array($row) ? ($row['name'] ?? null) : null;

        if (is_string($name) && $name !== '') {
            $sourceTables[$name] = true;
        }
    }

    $missingTables = array_values(array_filter($tables, fn (string $table): bool => !isset($sourceTables[$table])));

    if ($missingTables !== []) {
        $this->components->warn('Skipping missing SQLite tables: '.implode(', ', $missingTables));
    }

    $importCounts = [];

    $target->transaction(function () use ($sqlite, $target, $tables, &$importCounts): void {
        foreach (array_reverse($tables) as $table) {
            if ($target->getSchemaBuilder()->hasTable($table)) {
                $target->table($table)->delete();
            }
        }

        foreach ($tables as $table) {
            if (!$target->getSchemaBuilder()->hasTable($table)) {
                continue;
            }

            $rows = $sqlite->query('SELECT * FROM "'.$table.'"')->fetchAll();
            $importCounts[$table] = count($rows);

            if ($rows === []) {
                continue;
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                $target->table($table)->insert($chunk);
            }

            $idInfo = $sqlite->query("PRAGMA table_info('$table')")->fetchAll();
            $hasIntegerId = collect($idInfo)->contains(function (array $column): bool {
                return ($column['name'] ?? null) === 'id'
                    && (($column['pk'] ?? 0) === 1 || (int) ($column['pk'] ?? 0) === 1)
                    && str_contains(strtolower((string) ($column['type'] ?? '')), 'int');
            });

            if (!$hasIntegerId) {
                continue;
            }

            $sequenceRow = $target->selectOne('SELECT pg_get_serial_sequence(?, ?) AS sequence_name', [$table, 'id']);
            $sequence = is_object($sequenceRow) ? ($sequenceRow->sequence_name ?? null) : null;

            if (!is_string($sequence) || $sequence === '') {
                continue;
            }

            $maxId = $target->table($table)->max('id');
            $maxId = is_numeric($maxId) ? (int) $maxId : 0;
            $target->unprepared(sprintf(
                "SELECT setval('%s', %d, %s)",
                str_replace("'", "''", $sequence),
                max($maxId, 1),
                $maxId > 0 ? 'true' : 'false',
            ));
        }
    });

    $rows = [];

    foreach ($tables as $table) {
        if (!array_key_exists($table, $importCounts)) {
            continue;
        }

        $rows[] = [$table, (string) $importCounts[$table]];
    }

    $this->table(['Table', 'Imported rows'], $rows);
    $this->components->info('SQLite import completed from '.$sourcePath.' into the active pgsql connection.');

    return 0;
})->purpose('Import SQLite rows into the active pgsql database connection');

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

Artisan::command('camera-recordings:tick', function (): int {
    $scheduledFor = now()->utc()->startOfMinute();
    $recordings = app(CameraRecordingService::class);
    $recovered = $recordings->recoverStalePendingRecordings();
    $continuousRecorders = app(ContinuousRecordingSegmenterService::class);
    $continuousStopped = $continuousRecorders->stopUnmanagedRecorders();
    $motionRecorders = app(MotionRecordingSegmenterService::class);
    $motionStopped = $motionRecorders->stopUnmanagedRecorders();
    $continuousStarted = 0;
    $continuousImported = 0;
    $motionStarted = 0;
    $motionFinalized = 0;
    $queued = 0;

    Camera::query()
        ->where('is_enabled', true)
        ->where('supports_rtsp', true)
        ->whereIn('recording_mode', [Camera::RECORDING_MODE_CONTINUOUS, Camera::RECORDING_MODE_MOTION])
        ->orderBy('id')
        ->chunkById(50, function ($cameras) use ($scheduledFor, &$queued, &$continuousStarted, &$continuousImported, &$motionStarted, &$motionFinalized, $recordings, $continuousRecorders): void {
            foreach ($cameras as $camera) {
                if (!$camera->hasRecordingEnabled()) {
                    continue;
                }

                if ($camera->recording_mode === Camera::RECORDING_MODE_CONTINUOUS) {
                    if (!$continuousRecorders->enabled()) {
                        $recording = $recordings->ensureContinuousRecordingQueued($camera, now()->utc());

                        if (!$recording instanceof CameraRecording) {
                            continue;
                        }

                        if ($recordings->dispatchRecording(
                            $recording,
                            'Queued by scheduler for continuous capture at '.$recording->scheduled_for?->format('Y-m-d H:i:s').' UTC.',
                        )) {
                            $queued++;
                        }

                        continue;
                    }

                    $source = $recordings->resolveRecordingSource($camera);

                    if ($source === null) {
                        $continuousRecorders->stop($camera);

                        continue;
                    }

                    $result = $continuousRecorders->syncCamera($camera, $source);

                    if ($result['started']) {
                        $continuousStarted++;
                    }

                    $continuousImported += $result['imported'];

                    continue;
                }

                $result = $recordings->syncMotionRecorder($camera);

                if ($result['started']) {
                    $motionStarted++;
                }

                if ($result['finalized'] > 0) {
                    $motionFinalized += $result['finalized'];
                }
            }
        });

    $message = 'Queued '.$queued.' camera recording job'.($queued === 1 ? '' : 's').' for '.$scheduledFor->format('Y-m-d H:i').' UTC.';

    if ($recovered > 0) {
        $message .= ' Recovered '.$recovered.' stale pending segment'.($recovered === 1 ? '' : 's').'.';
    }

    if ($continuousStarted > 0) {
        $message .= ' Started '.$continuousStarted.' continuous recorder process'.($continuousStarted === 1 ? '' : 'es').'.';
    }

    if ($continuousImported > 0) {
        $message .= ' Imported '.$continuousImported.' continuous segment'.($continuousImported === 1 ? '' : 's').'.';
    }

    if ($continuousStopped > 0) {
        $message .= ' Stopped '.$continuousStopped.' unmanaged continuous recorder process'.($continuousStopped === 1 ? '' : 'es').'.';
    }

    if ($motionStarted > 0) {
        $message .= ' Started '.$motionStarted.' motion recorder process'.($motionStarted === 1 ? '' : 'es').'.';
    }

    if ($motionFinalized > 0) {
        $message .= ' Finalized '.$motionFinalized.' motion event'.($motionFinalized === 1 ? '' : 's').'.';
    }

    if ($motionStopped > 0) {
        $message .= ' Stopped '.$motionStopped.' unmanaged motion recorder process'.($motionStopped === 1 ? '' : 'es').'.';
    }

    $this->components->info($message);

    return 0;
})->purpose('Queue recording work for cameras with active recording policies');

Artisan::command('camera-recordings:ensure-worker', function (): int {
    $result = app(RecordingWorkerService::class)->ensureRunning();

    if ($result['ok']) {
        $this->components->info($result['message']);

        return 0;
    }

    $this->components->error($result['message']);

    return 1;
})->purpose('Ensure the bounded recordings queue worker is running');

Artisan::command('camera-recordings:install-worker-service {--no-start} {--graceful}', function (): int {
    $result = app(RecordingWorkerService::class)->installSystemdUserService(!$this->option('no-start'));

    if ($result['ok']) {
        $this->components->info($result['message'].' Path: '.$result['path']);

        return 0;
    }

    if ($this->option('graceful')) {
        $this->components->warn($result['message'].' Path: '.$result['path']);

        return 0;
    }

    $this->components->error($result['message'].' Path: '.$result['path']);

    return 1;
})->purpose('Install and optionally start the recordings worker user systemd unit');

Artisan::command('camera-recordings:prune', function (): int {
    $recordings = app(CameraRecordingService::class);
    $reconciled = $recordings->reconcileMissingRecordedFiles();
    $deleted = $recordings->pruneExpiredRecordings();

    $message = 'Pruned '.$deleted.' expired recording segment'.($deleted === 1 ? '' : 's').'.';

    if ($reconciled > 0) {
        $message .= ' Reconciled '.$reconciled.' recorded row'.($reconciled === 1 ? '' : 's').' whose segment file was already missing.';
    }

    $this->components->info($message);

    return 0;
})->purpose('Delete expired camera recording segments based on per-camera retention policies');

Artisan::command('camera-recordings:prune-audit {--camera_id=}', function (): int {
    $cameraId = $this->option('camera_id') !== null ? (int) $this->option('camera_id') : null;
    $rows = app(CameraRecordingService::class)->expiredRecordingAuditRows($cameraId);

    if ($rows === []) {
        $this->components->info('No recording segments are currently eligible for prune.');

        return 0;
    }

    $displayRows = array_slice($rows, 0, 50);

    $this->table(['Recording', 'Camera', 'Retention days', 'Created at UTC', 'Ended at UTC', 'Cutoff UTC', 'File', 'Relative path'], array_map(
        fn (array $row): array => [
            (string) ($row['recording_id'] ?? ''),
            (string) ($row['camera_name'] ?? '').' (#'.(string) ($row['camera_id'] ?? '').')',
            (string) ($row['retention_days'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            (string) ($row['ended_at'] ?? ''),
            (string) ($row['cutoff_at'] ?? ''),
            (string) ($row['file_present'] ?? ''),
            (string) ($row['relative_path'] ?? ''),
        ],
        $displayRows,
    ));

    $message = 'Found '.count($rows).' recording segment'.(count($rows) === 1 ? '' : 's').' eligible for prune based on created_at retention cutoffs.';

    if (count($rows) > count($displayRows)) {
        $message .= ' Showing the first '.count($displayRows).' entries.';
    }

    $this->components->warn($message);

    return 0;
})->purpose('Audit expired camera recording segments without deleting files or rows');

Artisan::command('camera-recordings:orphans {--purge}', function (): int {
    $storage = app(CameraStorageService::class);
    $orphans = $storage->orphanRecordingFiles();
    $count = count($orphans);
    $bytes = array_sum(array_map(fn (array $orphan): int => (int) ($orphan['size_bytes'] ?? 0), $orphans));

    if ($count === 0) {
        $this->components->info('No orphan recording files found.');

        return 0;
    }

    $displayRows = array_slice($orphans, 0, 20);

    $this->table(['Recording path', 'Bytes', 'Modified', 'Review assets'], array_map(
        fn (array $orphan): array => [
            (string) ($orphan['relative_path'] ?? ''),
            (string) ((int) ($orphan['size_bytes'] ?? 0)),
            (string) ($orphan['modified_at'] ?? 'n/a'),
            ((int) ($orphan['review_assets_present'] ?? 0)) === 1 ? 'yes' : 'no',
        ],
        $displayRows,
    ));

    $summary = 'Found '.$count.' orphan recording file'.($count === 1 ? '' : 's').' using '.$bytes.' byte'.($bytes === 1 ? '' : 's').'.';

    if ($count > count($displayRows)) {
        $summary .= ' Showing the first '.count($displayRows).' entries.';
    }

    $this->components->warn($summary);

    if (!$this->option('purge')) {
        $this->components->info('Run camera-recordings:orphans --purge to delete these orphan files and their review assets.');

        return 0;
    }

    $result = $storage->purgeOrphanRecordingFiles($orphans);

    $this->components->info(
        'Purged '.$result['files_purged'].' orphan recording file'.($result['files_purged'] === 1 ? '' : 's')
        .' and '.$result['review_directories_purged'].' review asset director'.($result['review_directories_purged'] === 1 ? 'y' : 'ies')
        .', freeing '.$result['bytes_freed'].' byte'.($result['bytes_freed'] === 1 ? '' : 's').'.'
    );

    return 0;
})->purpose('Audit orphan recording files and optionally purge them from private storage');

Artisan::command('camera-recordings:build-review-assets {--camera_id=} {--missing}', function (): int {
    $reviewAssets = app(RecordingReviewAssetService::class);
    $generated = 0;
    $skipped = 0;

    CameraRecording::query()
        ->where('status', CameraRecording::STATUS_RECORDED)
        ->whereNotNull('relative_path')
        ->when($this->option('camera_id'), function ($query): void {
            $query->where('camera_id', (int) $this->option('camera_id'));
        })
        ->orderBy('id')
        ->chunkById(50, function ($recordings) use (&$generated, &$skipped, $reviewAssets): void {
            foreach ($recordings as $recording) {
                if ($this->option('missing') && $reviewAssets->assetState($recording)['ready']) {
                    $skipped++;

                    continue;
                }

                GenerateRecordingReviewAssetsJob::dispatchSync($recording->id);
                $generated++;
            }
        });

    $message = 'Generated review assets for '.$generated.' recording'.($generated === 1 ? '' : 's').'.';

    if ($skipped > 0) {
        $message .= ' Skipped '.$skipped.' recording'.($skipped === 1 ? '' : 's').' with ready assets.';
    }

    $this->components->info($message);

    return 0;
})->purpose('Build scrub preview assets for saved recording segments');

Schedule::command('camera-fleet:refresh-previews')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('camera-recordings:ensure-worker')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('camera-recordings:tick')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('camera-recordings:prune')
    ->hourly()
    ->withoutOverlapping();
