<?php

namespace Tests\Feature;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use App\Services\CameraStorageService;
use App\Services\RecordingReviewAssetService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Concerns\BuildsFakeRecordingFfmpegBinary;
use Tests\TestCase;

class CameraRecordingMaintenanceCommandTest extends TestCase
{
    use BuildsFakeRecordingFfmpegBinary;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_records_motion_segments_and_prunes_expired_segments(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.post_trigger_seconds', 4);

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
            'motion_sensitivity' => 25,
            'recording_motion_mask' => [
                'version' => 1,
                'grid_width' => 4,
                'grid_height' => 4,
                'selected_pixels' => 4,
                'runs' => [
                    [0, 1],
                    [4, 5],
                ],
            ],
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner')]);

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
        File::put($expiredReviewAssetDirectory.'/manifest.json', '{}');
        File::put($expiredReviewAssetDirectory.'/scrub-sprite.jpg', 'sprite');

        $expiredLocalSpritePath = app(CameraStorageService::class)->recordingLocalReviewSpriteAbsolutePath(
            'cameras/'.$camera->id.'/recordings/2026/04/01/expired-continuous.mkv',
            'scrub-sprite.jpg',
            true,
        );
        File::put($expiredLocalSpritePath, 'local-sprite');

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
        $this->assertFileDoesNotExist($expiredLocalSpritePath);
    }

    public function test_it_prunes_expired_terminal_motion_rows_without_saved_files(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Quiet Lane',
            'local_ip' => '192.168.1.77',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream7',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
        ]);

        $scheduledFor = now()->utc()->subDays(2)->startOfMinute();

        CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_SKIPPED,
            'scheduled_for' => $scheduledFor,
            'started_at' => $scheduledFor,
            'ended_at' => $scheduledFor->copy()->addSeconds(33),
            'motion_score' => 0.0420,
            'message' => 'Legacy quiet motion evaluation.',
            'created_at' => $scheduledFor,
            'updated_at' => $scheduledFor,
        ]);

        Artisan::call('camera-recordings:prune');

        $this->assertDatabaseCount('camera_recordings', 0);
    }

    public function test_it_marks_recorded_rows_failed_when_the_segment_file_is_missing(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Archive Gate',
            'local_ip' => '192.168.1.78',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream8',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 7,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subMinutes(15)->startOfMinute(),
            'started_at' => now()->utc()->subMinutes(15)->startOfMinute(),
            'ended_at' => now()->utc()->subMinutes(14)->startOfMinute(),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/missing-archive-gate.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Previously recorded clip.',
        ]);

        $reviewAssets = app(RecordingReviewAssetService::class);
    $manifestAbsolutePath = app(CameraStorageService::class)->recordingReviewAssetAbsolutePath($recording->relative_path, 'manifest.json', true);
        $scrubSpriteAbsolutePath = $reviewAssets->scrubSpriteAbsolutePath($recording, true);
    File::put($manifestAbsolutePath, '{}');
        File::put($scrubSpriteAbsolutePath, 'sprite');

        Artisan::call('camera-recordings:prune');

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_FAILED, $recording->status);
        $this->assertNull($recording->file_size_bytes);
        $this->assertStringContainsString('missing from active storage', (string) $recording->message);
        $this->assertFileDoesNotExist($manifestAbsolutePath);
        $this->assertFileDoesNotExist($scrubSpriteAbsolutePath);
    }

    public function test_it_does_not_mark_recorded_rows_failed_when_storage_cannot_confirm_the_file_state(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Archive Gate',
            'local_ip' => '192.168.1.178',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream8',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 7,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => now()->utc()->subMinutes(15)->startOfMinute(),
            'started_at' => now()->utc()->subMinutes(15)->startOfMinute(),
            'ended_at' => now()->utc()->subMinutes(14)->startOfMinute(),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/transient-storage-check.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Previously recorded clip.',
        ]);

        $reviewAssets = app(RecordingReviewAssetService::class);
    $manifestAbsolutePath = app(CameraStorageService::class)->recordingReviewAssetAbsolutePath($recording->relative_path, 'manifest.json', true);
        $scrubSpriteAbsolutePath = $reviewAssets->scrubSpriteAbsolutePath($recording, true);
    File::put($manifestAbsolutePath, '{}');
        File::put($scrubSpriteAbsolutePath, 'sprite');

        $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])->makePartial();
        $storage->shouldReceive('recordingAvailability')
            ->with($recording->relative_path)
            ->once()
            ->andReturn(CameraStorageService::RECORDING_AVAILABILITY_UNREACHABLE);
        $this->app->instance(CameraStorageService::class, $storage);

        Artisan::call('camera-recordings:prune');

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertSame(4096, $recording->file_size_bytes);
        $this->assertSame('Previously recorded clip.', $recording->message);
        $this->assertFileExists($manifestAbsolutePath);
        $this->assertFileExists($scrubSpriteAbsolutePath);
    }

    public function test_it_recovers_previously_reconciled_recordings_when_the_file_is_available_again(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Archive Gate',
            'local_ip' => '192.168.1.179',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream8',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 7,
        ]);

        $relativePath = 'cameras/'.$camera->id.'/recordings/2026/04/07/recovered-archive-gate.mkv';
        $absolutePath = storage_path('app/private/'.$relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, 'recovered');

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_FAILED,
            'scheduled_for' => now()->utc()->subMinutes(15)->startOfMinute(),
            'started_at' => now()->utc()->subMinutes(15)->startOfMinute(),
            'ended_at' => now()->utc()->subMinutes(14)->startOfMinute(),
            'relative_path' => $relativePath,
            'file_size_bytes' => null,
            'message' => 'Saved recording file is missing from active storage. Marked failed by the hourly maintenance pass.',
        ]);

        Artisan::call('camera-recordings:prune');

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertSame(filesize($absolutePath), $recording->file_size_bytes);
        $this->assertSame('Recovered the recorded segment after storage became reachable again.', $recording->message);

        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($recording): bool {
            return $job->recordingId === $recording->getKey();
        });
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
            File::put($reviewAssetDirectory.'/manifest.json', '{}');

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
    $orphanManifestAbsolutePath = $storage->recordingReviewAssetAbsolutePath($orphanRelativePath, 'manifest.json', true);
    File::put($orphanManifestAbsolutePath, '{}');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));

        Artisan::call('camera-recordings:orphans');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));
        $this->assertFileExists($trackedAbsolutePath);
        $this->assertFileExists($orphanAbsolutePath);
        $this->assertFileExists($orphanManifestAbsolutePath);
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
        File::put($reviewDirectory.'/manifest.json', '{}');

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
        $this->assertFileDoesNotExist($reviewDirectory.'/manifest.json');
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
    $orphanManifestAbsolutePath = $storage->recordingReviewAssetAbsolutePath($orphanRelativePath, 'manifest.json', true);
    File::put($orphanManifestAbsolutePath, '{}');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));

        Artisan::call('camera-recordings:orphans', ['--purge' => true]);

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertFalse($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));
        $this->assertFileExists($trackedAbsolutePath);
        $this->assertFileDoesNotExist($orphanAbsolutePath);
        $this->assertFileDoesNotExist($orphanManifestAbsolutePath);
    }

    public function test_it_registers_the_recording_scheduler_commands(): void
    {
        $reviewBackfillEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'camera-recordings:build-review-assets'));

        $tickEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'camera-recordings:tick'));

        $pruneEvent = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'camera-recordings:prune'));

        $this->assertNotNull($reviewBackfillEvent);
        $this->assertSame('* * * * *', $reviewBackfillEvent->expression);
        $this->assertStringContainsString('--missing', (string) $reviewBackfillEvent->command);
        $this->assertStringNotContainsString("--missing='1'", (string) $reviewBackfillEvent->command);
        $this->assertNotNull($tickEvent);
        $this->assertSame('* * * * *', $tickEvent->expression);
        $this->assertNotNull($pruneEvent);
        $this->assertSame('0 * * * *', $pruneEvent->expression);
    }

    public function test_it_skips_overlapping_prune_invocations_when_the_lock_is_already_held(): void
    {
        $lock = Cache::lock('camera-recordings:prune-command', 3600);
        $this->assertTrue($lock->get());

        try {
            $exitCode = Artisan::call('camera-recordings:prune');

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('already running', Artisan::output());
        } finally {
            rescue(static fn () => $lock->release(), report: false);
        }
    }

    public function test_it_reports_prune_phase_progress_in_command_output(): void
    {
        $output = new BufferedOutput();
        $exitCode = Artisan::call('camera-recordings:prune', [], $output);
        $buffer = $output->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Starting missing-recording recovery pass...', $buffer);
        $this->assertStringContainsString('Starting recorded-row reconciliation pass...', $buffer);
        $this->assertStringContainsString('Starting expired recording prune pass...', $buffer);
        $this->assertStringContainsString('Pruned 0 expired recording segments.', $buffer);
    }

    public function test_it_can_scope_the_prune_command_to_a_single_camera(): void
    {
        $cameraOne = Camera::query()->create([
            'name' => 'Scoped Recovery One',
            'local_ip' => '192.168.1.181',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 7,
        ]);

        $cameraTwo = Camera::query()->create([
            'name' => 'Scoped Recovery Two',
            'local_ip' => '192.168.1.182',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 7,
        ]);

        $cameraOnePath = 'cameras/'.$cameraOne->id.'/recordings/2026/04/07/scoped-recovery-one.mkv';
        $cameraTwoPath = 'cameras/'.$cameraTwo->id.'/recordings/2026/04/07/scoped-recovery-two.mkv';

        File::ensureDirectoryExists(dirname(storage_path('app/private/'.$cameraOnePath)));
        File::put(storage_path('app/private/'.$cameraOnePath), 'camera-one');
        File::ensureDirectoryExists(dirname(storage_path('app/private/'.$cameraTwoPath)));
        File::put(storage_path('app/private/'.$cameraTwoPath), 'camera-two');

        $cameraOneRecording = CameraRecording::query()->create([
            'camera_id' => $cameraOne->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_FAILED,
            'scheduled_for' => now()->utc()->subMinutes(20)->startOfMinute(),
            'started_at' => now()->utc()->subMinutes(20)->startOfMinute(),
            'ended_at' => now()->utc()->subMinutes(19)->startOfMinute(),
            'relative_path' => $cameraOnePath,
            'file_size_bytes' => null,
            'message' => 'Saved recording file is missing from active storage. Marked failed by the hourly maintenance pass.',
        ]);

        $cameraTwoRecording = CameraRecording::query()->create([
            'camera_id' => $cameraTwo->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_FAILED,
            'scheduled_for' => now()->utc()->subMinutes(18)->startOfMinute(),
            'started_at' => now()->utc()->subMinutes(18)->startOfMinute(),
            'ended_at' => now()->utc()->subMinutes(17)->startOfMinute(),
            'relative_path' => $cameraTwoPath,
            'file_size_bytes' => null,
            'message' => 'Saved recording file is missing from active storage. Marked failed by the hourly maintenance pass.',
        ]);

        Artisan::call('camera-recordings:prune', ['--camera_id' => $cameraOne->id]);

        $cameraOneRecording->refresh();
        $cameraTwoRecording->refresh();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $cameraOneRecording->status);
        $this->assertSame(CameraRecording::STATUS_FAILED, $cameraTwoRecording->status);
    }
}
