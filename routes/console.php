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

Artisan::command('camera-recordings:tick', function (): int {
    $scheduledFor = now()->utc()->startOfMinute();
    $recordings = app(CameraRecordingService::class);
    $recovered = $recordings->recoverStalePendingRecordings();
    $continuousRecorders = app(ContinuousRecordingSegmenterService::class);
    $continuousStopped = $continuousRecorders->stopUnmanagedRecorders();
    $continuousStarted = 0;
    $continuousImported = 0;
    $queued = 0;

    Camera::query()
        ->where('is_enabled', true)
        ->where('supports_rtsp', true)
        ->whereIn('recording_mode', [Camera::RECORDING_MODE_CONTINUOUS, Camera::RECORDING_MODE_MOTION])
        ->orderBy('id')
        ->chunkById(50, function ($cameras) use ($scheduledFor, &$queued, &$continuousStarted, &$continuousImported, $recordings, $continuousRecorders): void {
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

                if ($recordings->isMotionRecordingActive($camera)) {
                    continue;
                }

                $recording = CameraRecording::query()->firstOrCreate([
                    'camera_id' => $camera->id,
                    'scheduled_for' => $scheduledFor,
                ], [
                    'capture_mode' => $camera->recording_mode,
                    'status' => CameraRecording::STATUS_QUEUED,
                    'message' => 'Queued by scheduler.',
                ]);

                if (!$recording->wasRecentlyCreated) {
                    continue;
                }

                if ($recordings->dispatchRecording($recording, 'Queued by scheduler for '.$scheduledFor->format('Y-m-d H:i').' UTC.')) {
                    $queued++;
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
    $deleted = app(CameraRecordingService::class)->pruneExpiredRecordings();

    $this->components->info('Pruned '.$deleted.' expired recording segment'.($deleted === 1 ? '' : 's').'.');

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
