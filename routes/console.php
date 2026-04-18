<?php

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Jobs\RefreshCameraPreviewJob;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use App\Services\CameraRecordingService;
use App\Services\CameraStorageService;
use App\Services\RecordingContainerHealthService;
use App\Services\RuntimeHeartbeatService;
use App\Services\RecordingReviewAssetService;
use App\Services\ContinuousRecordingSegmenterService;
use App\Services\FailedJobRetryService;
use App\Services\MotionRecordingSegmenterService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

                    $source = $recordings->resolveBufferedRecordingSource($camera)
                        ?? $recordings->resolveRecordingSource($camera);

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
    app(RuntimeHeartbeatService::class)->touchRecordingTick('camera-recordings:tick');

    return 0;
})->purpose('Queue recording work for cameras with active recording policies');

Artisan::command('camera-recordings:healthcheck {role}', function (RecordingContainerHealthService $health): int {
    try {
        $result = $health->check((string) $this->argument('role'));
    } catch (\InvalidArgumentException $exception) {
        $this->components->error($exception->getMessage());

        return 1;
    }

    if ($result['ok']) {
        $this->components->info($result['summary']);

        return 0;
    }

    foreach ($result['checks'] as $check) {
        if ($check['ok']) {
            continue;
        }

        $this->components->error($check['message']);
    }

    return 1;
})->purpose('Validate app, worker, or scheduler container health for recording operations');

Artisan::command('camera-recordings:prune {--camera_id=}', function (): int {
    $lock = Cache::lock('camera-recordings:prune-command', max(900, (int) config('recording.prune_lock_seconds', 3600)));

    if (!$lock->get()) {
        $this->components->warn('camera-recordings:prune is already running. Skipping this invocation.');

        return 0;
    }

    $recordings = app(CameraRecordingService::class);
    $cameraId = $this->option('camera_id') !== null ? (int) $this->option('camera_id') : null;

    try {
        $this->components->info('Starting missing-recording recovery pass...');
        $recovered = $recordings->recoverPreviouslyMissingRecordedFiles($cameraId);
        $this->components->info('Starting recorded-row reconciliation pass...');
        $reconciled = $recordings->reconcileMissingRecordedFiles($cameraId);
        $this->components->info('Starting expired recording prune pass...');
        $deleted = $recordings->pruneExpiredRecordings($cameraId);

        $message = 'Pruned '.$deleted.' expired recording segment'.($deleted === 1 ? '' : 's').'.';

        if ($recovered > 0) {
            $message .= ' Recovered '.$recovered.' previously reconciled recording row'.($recovered === 1 ? '' : 's').' after storage became reachable again.';
        }

        if ($reconciled > 0) {
            $message .= ' Reconciled '.$reconciled.' recorded row'.($reconciled === 1 ? '' : 's').' whose segment file was already missing.';
        }

        $this->components->info($message);

        return 0;
    } finally {
        rescue(static fn () => $lock->release(), report: false);
    }
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

Artisan::command('camera-recordings:build-review-assets {--camera_id=} {--missing} {--limit=}', function (): int {
    $reviewAssets = app(RecordingReviewAssetService::class);
    $generated = 0;
    $failed = 0;
    $skipped = 0;

    $limitOption = trim((string) ($this->option('limit') ?? ''));
    $limit = null;

    if ($limitOption !== '') {
        if (!ctype_digit($limitOption) || (int) $limitOption < 1) {
            $this->components->error('limit must be a positive integer.');

            return 1;
        }

        $limit = (int) $limitOption;
    }

    $buildAssets = function ($recordings) use (&$generated, &$failed, &$skipped, $reviewAssets): void {
        foreach ($recordings as $recording) {
            if ($this->option('missing') && $reviewAssets->hasReadyAssets($recording, true)) {
                $skipped++;

                continue;
            }

            try {
                GenerateRecordingReviewAssetsJob::dispatchSync($recording->id);
                $generated++;
            } catch (\Throwable $exception) {
                $failed++;
            }
        }
    };

    $query = CameraRecording::query()
        ->where('status', CameraRecording::STATUS_RECORDED)
        ->whereNotNull('relative_path')
        ->when($this->option('camera_id'), function ($query): void {
            $query->where('camera_id', (int) $this->option('camera_id'));
        });

    if ($limit !== null) {
        $buildAssets(
            $query
                ->orderByDesc('started_at')
                ->orderByDesc('id')
                ->limit($limit)
                ->get()
        );
    } else {
        $query
            ->orderBy('id')
            ->chunkById(50, function ($recordings) use ($buildAssets): void {
                $buildAssets($recordings);
            });
    }

    $message = 'Generated review assets for '.$generated.' recording'.($generated === 1 ? '' : 's').'.';

    if ($limit !== null) {
        $message .= ' Limit '.$limit.'.';
    }

    if ($skipped > 0) {
        $message .= ' Skipped '.$skipped.' recording'.($skipped === 1 ? '' : 's').' with ready assets.';
    }

    if ($failed > 0) {
        $message .= ' Failed '.$failed.' recording'.($failed === 1 ? '' : 's').'.';
    }

    $this->components->info($message);

    return 0;
})->purpose('Build scrub preview assets for saved recording segments');

Artisan::command('camera-recordings:queue-review-assets {--camera_id=} {--date_from=} {--date_to=}', function (): int {
    $reviewAssets = app(RecordingReviewAssetService::class);
    $settings = app(ApplicationSettingsService::class);
    $cameraIdOption = trim((string) ($this->option('camera_id') ?? ''));
    $dateFromOption = trim((string) ($this->option('date_from') ?? ''));
    $dateToOption = trim((string) ($this->option('date_to') ?? ''));

    if ($cameraIdOption === '' && $dateFromOption === '' && $dateToOption === '') {
        $this->components->error('Provide --camera_id and/or --date_from/--date_to to keep the backfill scope selective.');

        return 1;
    }

    $cameraId = null;

    if ($cameraIdOption !== '') {
        if (!ctype_digit($cameraIdOption) || (int) $cameraIdOption < 1) {
            $this->components->error('camera_id must be a positive integer.');

            return 1;
        }

        $cameraId = (int) $cameraIdOption;
    }

    $rangeStart = null;
    $rangeEndExclusive = null;

    if ($dateFromOption !== '') {
        try {
            $rangeStart = $settings->startOfDisplayDayUtc($dateFromOption);
        } catch (\Throwable) {
            $this->components->error('date_from must use the YYYY-MM-DD format in '.$settings->appTimezone().'.');

            return 1;
        }
    }

    if ($dateToOption !== '') {
        try {
            $rangeEndExclusive = $settings->startOfDisplayDayUtc($dateToOption)->addDay();
        } catch (\Throwable) {
            $this->components->error('date_to must use the YYYY-MM-DD format in '.$settings->appTimezone().'.');

            return 1;
        }
    }

    if ($rangeStart !== null && $rangeEndExclusive !== null && $rangeEndExclusive->lessThanOrEqualTo($rangeStart)) {
        $this->components->error('date_to must be the same day as or after date_from.');

        return 1;
    }

    $selected = 0;
    $queued = 0;
    $skipped = 0;

    CameraRecording::query()
        ->where('status', CameraRecording::STATUS_RECORDED)
        ->whereNotNull('relative_path')
        ->when($cameraId !== null, function ($query) use ($cameraId): void {
            $query->where('camera_id', $cameraId);
        })
        ->when($rangeStart !== null, function ($query) use ($rangeStart): void {
            $query->where('scheduled_for', '>=', $rangeStart);
        })
        ->when($rangeEndExclusive !== null, function ($query) use ($rangeEndExclusive): void {
            $query->where('scheduled_for', '<', $rangeEndExclusive);
        })
        ->orderBy('id')
        ->chunkById(50, function ($recordings) use (&$selected, &$queued, &$skipped, $reviewAssets): void {
            foreach ($recordings as $recording) {
                $selected++;

                if ($reviewAssets->ensureQueued($recording, true)) {
                    $queued++;

                    continue;
                }

                $skipped++;
            }
        });

    if ($selected === 0) {
        $this->components->info('No recorded segments matched the selected backfill scope.');

        return 0;
    }

    $scopeParts = [];

    if ($cameraId !== null) {
        $scopeParts[] = 'camera '.$cameraId;
    }

    if ($dateFromOption !== '' || $dateToOption !== '') {
        $dateSummary = $dateFromOption !== ''
            ? ($dateToOption !== '' ? $dateFromOption.' through '.$dateToOption : 'from '.$dateFromOption)
            : 'through '.$dateToOption;

        $scopeParts[] = $dateSummary.' in '.$settings->appTimezone();
    }

    $message = 'Queued review-asset generation for '.$queued.' recording'.($queued === 1 ? '' : 's').'.';

    if ($skipped > 0) {
        $message .= ' Skipped '.$skipped.' recording'.($skipped === 1 ? '' : 's').' that were already ready or already pending.';
    }

    if ($scopeParts !== []) {
        $message .= ' Scope: '.implode(', ', $scopeParts).'.';
    }

    $this->components->info($message);

    return 0;
})->purpose('Queue selective scrub preview backfills for one camera or a display-date range');

Artisan::command('camera-recordings:reconcile-review-asset-queue {--dry-run}', function (): int {
    $result = app(RecordingReviewAssetService::class)->reconcileQueuedJobs((bool) $this->option('dry-run'));

    if (!$result['ok']) {
        $this->components->error($result['message']);

        return 1;
    }

    $message = ($this->option('dry-run') ? 'Dry run only. ' : '')
        .'Scanned '.$result['jobs_scanned'].' queued review-asset job'.($result['jobs_scanned'] === 1 ? '' : 's')
        .' across '.$result['recordings_matched'].' recording'.($result['recordings_matched'] === 1 ? '' : 's').'.'
        .' Deleted '.$result['jobs_deleted'].' duplicate job'.($result['jobs_deleted'] === 1 ? '' : 's').'.'
        .' Requeued '.$result['jobs_requeued'].' pending job'.($result['jobs_requeued'] === 1 ? '' : 's').' onto '.config('recording.review_assets.queue', 'review-assets').'.';

    if ($result['active_reserved_recordings'] > 0) {
        $message .= ' Left '.$result['active_reserved_recordings'].' recording'.($result['active_reserved_recordings'] === 1 ? '' : 's').' with actively reserved review jobs untouched.';
    }

    $this->components->info($message);

    return 0;
})->purpose('Deduplicate queued review-asset jobs and move pending legacy jobs onto the review-assets queue');

Artisan::command('queue:retry-failed-auto {--limit=}', function (): int {
    $limitOption = $this->option('limit');
    $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;
    $result = app(FailedJobRetryService::class)->retryBatch($limit);

    if (!$result['enabled']) {
        $this->components->info('Automatic failed-job retries are disabled or unavailable on this environment.');

        return 0;
    }

    $message = 'Scanned '.$result['scanned'].' eligible failed job record'.($result['scanned'] === 1 ? '' : 's').'. '
        .'Retried '.$result['retried'].' job'.($result['retried'] === 1 ? '' : 's').'.';

    if ($result['limit_reached'] > 0) {
        $message .= ' Left '.$result['limit_reached'].' job'.($result['limit_reached'] === 1 ? '' : 's').' at the configured retry limit.';
    }

    if ($result['errors'] > 0) {
        $message .= ' '.$result['errors'].' job'.($result['errors'] === 1 ? '' : 's').' could not be retried.';
    }

    $this->components->info($message);

    return $result['errors'] > 0 ? 1 : 0;
})->purpose('Requeue eligible failed jobs until the configured automatic retry limit is reached');

Schedule::command('camera-fleet:refresh-previews')
    ->everyThirtyMinutes()
    ->withoutOverlapping(45);

if ((bool) config('queue.failed.auto_retry.enabled', true) && (int) config('queue.failed.auto_retry.max_retries', 2) > 0) {
    Schedule::command('queue:retry-failed-auto --limit='.(string) config('queue.failed.auto_retry.batch_size', 5))
        ->everyMinute()
        ->withoutOverlapping(5);
}

if ((bool) config('recording.review_assets.scheduler_enabled', true)) {
    Schedule::command('camera-recordings:build-review-assets --missing --limit='.(string) config('recording.review_assets.scheduler_limit', 4))
        ->everyMinute()
        ->withoutOverlapping(15);
}

Schedule::command('camera-recordings:tick')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('camera-recordings:prune')
    ->hourly()
    ->withoutOverlapping(180);
