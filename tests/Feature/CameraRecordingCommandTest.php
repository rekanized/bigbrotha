<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\CameraRecordingService;
use App\Services\CameraStorageService;
use App\Services\RecordingReviewAssetService;
use Illuminate\Support\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CameraRecordingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_a_continuous_segment_for_an_enabled_camera(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'Front Door',
            'local_ip' => '192.168.1.67',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(Camera::RECORDING_MODE_CONTINUOUS, $recording->capture_mode);
        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertNotNull($recording->relative_path);
        $this->assertFileExists(storage_path('app/private/'.$recording->relative_path));

        $reviewAssets = app(RecordingReviewAssetService::class);

        $this->assertFileExists($reviewAssets->previewAbsolutePath($recording));
        $this->assertFileExists($reviewAssets->thumbnailAbsolutePath($recording));
        $this->assertFileExists($reviewAssets->scrubSpriteAbsolutePath($recording));
        $this->assertSame(RecordingReviewAssetService::STATUS_READY, $reviewAssets->assetState($recording)['status']);

        $camera->refresh();

        $this->assertNotNull($camera->recording_last_recorded_at);
    }

    public function test_it_reanchors_delayed_continuous_capture_to_the_actual_start_and_uses_exact_segment_bounds(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'South Gate',
            'local_ip' => '192.168.1.76',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream7',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => Carbon::create(2026, 4, 4, 18, 2, 0, 'UTC'),
            'message' => 'Queued by scheduler.',
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous')]);

        $this->travelTo(Carbon::create(2026, 4, 4, 18, 3, 29, 'UTC'));

        try {
            app(CameraRecordingService::class)->processRecording($recording);
        } finally {
            $this->travelBack();
        }

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertSame('2026-04-04 18:03:29', $recording->scheduled_for?->utc()->toDateTimeString());
        $this->assertSame('2026-04-04 18:03:29', $recording->started_at?->utc()->toDateTimeString());
        $this->assertSame('2026-04-04 18:04:29', $recording->ended_at?->utc()->toDateTimeString());
        $this->assertSame(60, (int) $recording->started_at?->diffInSeconds($recording->ended_at));
    }

    public function test_it_uses_rtsp_timeout_arguments_that_are_compatible_with_the_host_ffmpeg_build(): void
    {
        config()->set('queue.default', 'sync');

        Camera::query()->create([
            'name' => 'Hallway 2nd floor',
            'local_ip' => '192.168.1.71',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('reject-rw-timeout')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertFileExists(app(RecordingReviewAssetService::class)->scrubSpriteAbsolutePath($recording));
    }

    public function test_it_skips_motion_recording_when_the_detection_window_is_quiet(): void
    {
        config()->set('queue.default', 'sync');

        Camera::query()->create([
            'name' => 'Garage',
            'local_ip' => '192.168.1.68',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 60,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-quiet')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_SKIPPED, $recording->status);
        $this->assertNull($recording->relative_path);
        $this->assertStringContainsString('No motion crossed', (string) $recording->message);
    }

    public function test_it_marks_the_segment_failed_when_capture_errors_out(): void
    {
        config()->set('queue.default', 'sync');

        Camera::query()->create([
            'name' => 'Driveway',
            'local_ip' => '192.168.1.72',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream3',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('capture-fails')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_FAILED, $recording->status);
        $this->assertNull($recording->relative_path);
        $this->assertStringContainsString('simulated capture failure', (string) $recording->message);
    }

    public function test_it_recovers_a_stale_pending_segment_on_the_next_scheduler_tick(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'Lobby',
            'local_ip' => '192.168.1.73',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream4',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => now()->utc()->startOfMinute(),
            'message' => 'Stuck in queue.',
        ]);

        $recording->timestamps = false;
        $recording->forceFill([
            'updated_at' => now()->utc()->subMinutes(15),
        ])->save();
        $recording->timestamps = true;

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous')]);

        Artisan::call('camera-recordings:tick');

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertNotNull($recording->relative_path);
        $this->assertFileExists(storage_path('app/private/'.$recording->relative_path));
    }

    public function test_it_records_motion_segments_and_prunes_expired_segments(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'Back Gate',
            'local_ip' => '192.168.1.69',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 70,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-detected')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertNotNull($recording->relative_path);
        $this->assertFileExists(storage_path('app/private/'.$recording->relative_path));

        $camera->refresh();
        $this->assertNotNull($camera->recording_last_motion_at);

        $expiredFile = storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/01/expired-continuous.mkv');
        File::ensureDirectoryExists(dirname($expiredFile));
        File::put($expiredFile, 'expired');

        $expiredReviewAssetDirectory = storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/01/_review/expired-continuous');
        File::ensureDirectoryExists($expiredReviewAssetDirectory);
        File::put($expiredReviewAssetDirectory.'/preview.mp4', 'preview');
        File::put($expiredReviewAssetDirectory.'/poster.jpg', 'poster');

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subDays(2)->startOfMinute(),
            'started_at' => now()->utc()->subDays(2)->startOfMinute(),
            'ended_at' => now()->utc()->subDays(2)->startOfMinute()->addMinute(),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/01/expired-continuous.mkv',
            'file_size_bytes' => 7,
            'message' => 'Expired segment.',
            'created_at' => now()->utc()->subDays(2)->startOfMinute(),
            'updated_at' => now()->utc()->subDays(2)->startOfMinute(),
        ]);

        Artisan::call('camera-recordings:prune');

        $this->assertFileDoesNotExist($expiredFile);
        $this->assertDatabaseMissing('camera_recordings', [
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/01/expired-continuous.mkv',
        ]);
        $this->assertDirectoryDoesNotExist($expiredReviewAssetDirectory);
    }

    public function test_it_prunes_expired_segments_with_legacy_private_storage_path_formats(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Dock Door',
            'local_ip' => '192.168.1.70',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $appPrivateRelativePath = 'app/private/cameras/'.$camera->id.'/recordings/2026/04/01/expired-app-private.mkv';
        $absolutePath = storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/01/expired-absolute.mkv');

        foreach ([
            [
                'stored_path' => $appPrivateRelativePath,
                'absolute_path' => storage_path($appPrivateRelativePath),
            ],
            [
                'stored_path' => $absolutePath,
                'absolute_path' => $absolutePath,
            ],
        ] as $index => $fixture) {
            File::ensureDirectoryExists(dirname($fixture['absolute_path']));
            File::put($fixture['absolute_path'], 'expired');

            $reviewAssetDirectory = storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/01/_review/'.pathinfo($fixture['absolute_path'], PATHINFO_FILENAME));
            File::ensureDirectoryExists($reviewAssetDirectory);
            File::put($reviewAssetDirectory.'/preview.mp4', 'preview');

            $scheduledFor = now()->utc()->subDays(2)->startOfMinute()->addMinutes($index);

            CameraRecording::query()->create([
                'camera_id' => $camera->id,
                'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
                'status' => CameraRecording::STATUS_RECORDED,
                'scheduled_for' => $scheduledFor,
                'started_at' => $scheduledFor,
                'ended_at' => $scheduledFor->copy()->addMinute(),
                'relative_path' => $fixture['stored_path'],
                'file_size_bytes' => 7,
                'message' => 'Expired legacy segment.',
                'created_at' => $scheduledFor,
                'updated_at' => $scheduledFor,
            ]);
        }

        Artisan::call('camera-recordings:prune');

        $this->assertFileDoesNotExist(storage_path($appPrivateRelativePath));
        $this->assertFileDoesNotExist($absolutePath);
        $this->assertDirectoryDoesNotExist(storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/01/_review/expired-app-private'));
        $this->assertDirectoryDoesNotExist(storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/01/_review/expired-absolute'));
        $this->assertDatabaseMissing('camera_recordings', [
            'relative_path' => $appPrivateRelativePath,
        ]);
        $this->assertDatabaseMissing('camera_recordings', [
            'relative_path' => $absolutePath,
        ]);
    }

    public function test_it_audits_orphan_recording_files_without_deleting_them(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Warehouse',
            'local_ip' => '192.168.1.74',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream5',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $trackedRelativePath = 'cameras/'.$camera->id.'/recordings/2026/04/03/tracked.mkv';
        $trackedAbsolutePath = storage_path('app/private/'.$trackedRelativePath);
        File::ensureDirectoryExists(dirname($trackedAbsolutePath));
        File::put($trackedAbsolutePath, 'tracked');

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subMinute(),
            'started_at' => now()->utc()->subMinute(),
            'ended_at' => now()->utc(),
            'relative_path' => $trackedRelativePath,
            'file_size_bytes' => 7,
            'message' => 'Tracked segment.',
        ]);

        $orphanRelativePath = 'cameras/'.$camera->id.'/recordings/2026/04/03/orphan-audit.mkv';
        $orphanAbsolutePath = storage_path('app/private/'.$orphanRelativePath);
        File::ensureDirectoryExists(dirname($orphanAbsolutePath));
        File::put($orphanAbsolutePath, 'orphan');

        $storage = app(CameraStorageService::class);
        $reviewDirectory = dirname($storage->recordingReviewAssetAbsolutePath($orphanRelativePath, '.', true));
        File::put($reviewDirectory.'/preview.mp4', 'preview');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));

        Artisan::call('camera-recordings:orphans');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));
        $this->assertFileExists($trackedAbsolutePath);
        $this->assertFileExists($orphanAbsolutePath);
        $this->assertDirectoryExists($reviewDirectory);
    }

    public function test_it_uses_created_at_for_retention_cutoffs_instead_of_ended_at(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Archive Lane',
            'local_ip' => '192.168.1.79',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream8',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $relativePath = 'cameras/'.$camera->id.'/recordings/2026/04/04/recently-created.mkv';
        $absolutePath = storage_path('app/private/'.$relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'recent');

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subDays(2)->startOfMinute(),
            'started_at' => now()->utc()->subDays(2)->startOfMinute(),
            'ended_at' => now()->utc()->subDays(2)->startOfMinute()->addMinute(),
            'relative_path' => $relativePath,
            'file_size_bytes' => 6,
            'message' => 'Recently imported segment.',
            'created_at' => now()->utc()->subHours(2),
            'updated_at' => now()->utc()->subHours(2),
        ]);

        Artisan::call('camera-recordings:prune');

        $this->assertFileExists($absolutePath);
        $this->assertDatabaseHas('camera_recordings', [
            'relative_path' => $relativePath,
        ]);
    }

    public function test_it_deletes_the_database_row_when_the_expired_segment_file_is_already_missing(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Missing Segment',
            'local_ip' => '192.168.1.80',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream9',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $relativePath = 'cameras/'.$camera->id.'/recordings/2026/04/01/missing-expired.mkv';
        $reviewDirectory = storage_path('app/private/cameras/'.$camera->id.'/recordings/2026/04/01/_review/missing-expired');
        File::ensureDirectoryExists($reviewDirectory);
        File::put($reviewDirectory.'/preview.mp4', 'preview');

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subDays(2)->startOfMinute(),
            'started_at' => now()->utc()->subDays(2)->startOfMinute(),
            'ended_at' => now()->utc()->subDays(2)->startOfMinute()->addMinute(),
            'relative_path' => $relativePath,
            'file_size_bytes' => 7,
            'message' => 'Expired segment with a missing file.',
            'created_at' => now()->utc()->subDays(2)->startOfMinute(),
            'updated_at' => now()->utc()->subDays(2)->startOfMinute(),
        ]);

        Artisan::call('camera-recordings:prune');

        $this->assertDatabaseMissing('camera_recordings', [
            'relative_path' => $relativePath,
        ]);
        $this->assertDirectoryDoesNotExist($reviewDirectory);
    }

    public function test_it_purges_orphan_recording_files_and_review_assets_when_requested(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Receiving',
            'local_ip' => '192.168.1.75',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream6',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $trackedRelativePath = 'cameras/'.$camera->id.'/recordings/2026/04/03/tracked-purge.mkv';
        $trackedAbsolutePath = storage_path('app/private/'.$trackedRelativePath);
        File::ensureDirectoryExists(dirname($trackedAbsolutePath));
        File::put($trackedAbsolutePath, 'tracked');

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subMinute(),
            'started_at' => now()->utc()->subMinute(),
            'ended_at' => now()->utc(),
            'relative_path' => $trackedRelativePath,
            'file_size_bytes' => 7,
            'message' => 'Tracked segment.',
        ]);

        $orphanRelativePath = 'cameras/'.$camera->id.'/recordings/2026/04/03/orphan-purge.mkv';
        $orphanAbsolutePath = storage_path('app/private/'.$orphanRelativePath);
        File::ensureDirectoryExists(dirname($orphanAbsolutePath));
        File::put($orphanAbsolutePath, 'orphan');

        $storage = app(CameraStorageService::class);
        $reviewDirectory = dirname($storage->recordingReviewAssetAbsolutePath($orphanRelativePath, '.', true));
        File::put($reviewDirectory.'/preview.mp4', 'preview');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));

        Artisan::call('camera-recordings:orphans', ['--purge' => true]);

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertFalse($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));
        $this->assertFileExists($trackedAbsolutePath);
        $this->assertFileDoesNotExist($orphanAbsolutePath);
        $this->assertDirectoryDoesNotExist($reviewDirectory);
    }

    public function test_it_registers_the_recording_scheduler_commands(): void
    {
        $ensureWorkerEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'camera-recordings:ensure-worker'));

        $tickEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'camera-recordings:tick'));

        $pruneEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'camera-recordings:prune'));

        $this->assertNotNull($ensureWorkerEvent);
        $this->assertSame('* * * * *', $ensureWorkerEvent->expression);
        $this->assertNotNull($tickEvent);
        $this->assertSame('* * * * *', $tickEvent->expression);
        $this->assertNotNull($pruneEvent);
        $this->assertSame('0 * * * *', $pruneEvent->expression);
    }

    private function fakeFfmpegBinary(string $mode): string
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $binaryPath = $binaryDirectory.'/ffmpeg-recording-'.$mode.'.sh';

        $script = match ($mode) {
            'reject-rw-timeout' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- '-rw_timeout'; then
    printf '%s\n' 'Option rw_timeout not found.' >&2
    exit 1
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
            'motion-detected' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -q 'showinfo'; then
    printf '%s\n' 'showinfo motion-detected' >&2
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
            'motion-quiet' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -q 'showinfo'; then
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
            'capture-fails' => <<<'BASH'
#!/usr/bin/env bash
set -e
printf '%s\n' 'simulated capture failure' >&2
exit 1
BASH,
            default => <<<'BASH'
#!/usr/bin/env bash
set -e
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
        };

        File::put($binaryPath, $script);
        chmod($binaryPath, 0755);

        return $binaryPath;
    }
}