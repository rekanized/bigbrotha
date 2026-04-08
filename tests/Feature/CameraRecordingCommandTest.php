<?php

namespace Tests\Feature;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use App\Services\CameraRecordingService;
use App\Services\CameraStorageService;
use App\Services\RecordingReviewAssetService;
use Illuminate\Support\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
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
        $assetState = $reviewAssets->assetState($recording);

        $this->assertFileExists($reviewAssets->previewAbsolutePath($recording));
        $this->assertFileExists($reviewAssets->scrubSpriteAbsolutePath($recording));
        $this->assertTrue((bool) ($assetState['thumbnail_available'] ?? false));
        $this->assertSame(RecordingReviewAssetService::STATUS_READY, $assetState['status']);

        $camera->refresh();

        $this->assertNotNull($camera->recording_last_recorded_at);
    }

    public function test_it_bootstraps_the_persistent_segmenter_when_a_legacy_continuous_row_runs(): void
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
        $importedRecording = CameraRecording::query()
            ->whereKeyNot($recording->getKey())
            ->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_SKIPPED, $recording->status);
        $this->assertStringContainsString('persistent segment muxer', (string) $recording->message);
        $this->assertSame(CameraRecording::STATUS_RECORDED, $importedRecording->status);
        $this->assertSame('2026-04-04 18:03:29', $importedRecording->scheduled_for?->utc()->toDateTimeString());
        $this->assertSame('2026-04-04 18:03:29', $importedRecording->started_at?->utc()->toDateTimeString());
        $this->assertSame('2026-04-04 18:04:29', $importedRecording->ended_at?->utc()->toDateTimeString());
        $this->assertSame(60, (int) $importedRecording->started_at?->diffInSeconds($importedRecording->ended_at));
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

    public function test_queue_review_assets_requires_a_selective_filter(): void
    {
        Queue::fake();

        $this->artisan('camera-recordings:queue-review-assets')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    public function test_queue_review_assets_can_target_one_camera(): void
    {
        Queue::fake();

        $selectedCamera = Camera::query()->create([
            'name' => 'Loading Dock',
            'local_ip' => '192.168.1.44',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $otherCamera = Camera::query()->create([
            'name' => 'Garage',
            'local_ip' => '192.168.1.45',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $selectedRecording = CameraRecording::query()->create([
            'camera_id' => $selectedCamera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 7, 9, 15, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 9, 15, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 9, 16, 0, 'UTC'),
            'relative_path' => 'cameras/'.$selectedCamera->id.'/recordings/2026/04/07/loading-dock.mkv',
            'message' => 'Clip saved.',
        ]);

        $otherRecording = CameraRecording::query()->create([
            'camera_id' => $otherCamera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 7, 9, 15, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 9, 15, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 9, 16, 0, 'UTC'),
            'relative_path' => 'cameras/'.$otherCamera->id.'/recordings/2026/04/07/garage.mkv',
            'message' => 'Clip saved.',
        ]);

        $this->artisan('camera-recordings:queue-review-assets', [
            '--camera_id' => (string) $selectedCamera->id,
        ])->assertSuccessful();

        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($selectedRecording): bool {
            return $job->recordingId === $selectedRecording->id;
        });
        Queue::assertNotPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($otherRecording): bool {
            return $job->recordingId === $otherRecording->id;
        });
    }

    public function test_queue_review_assets_can_target_a_display_date_range(): void
    {
        Queue::fake();

        app(ApplicationSettingsService::class)->saveAppTimezone('Europe/Amsterdam');

        $camera = Camera::query()->create([
            'name' => 'Back Entrance',
            'local_ip' => '192.168.1.81',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $matchingRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 7, 22, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 22, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 22, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/back-entrance-2230.mkv',
            'message' => 'Clip saved.',
        ]);

        $outsideRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 8, 22, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 8, 22, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 8, 22, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/08/back-entrance-2230-next-day.mkv',
            'message' => 'Clip saved.',
        ]);

        $this->artisan('camera-recordings:queue-review-assets', [
            '--date_from' => '2026-04-08',
            '--date_to' => '2026-04-08',
        ])->assertSuccessful();

        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($matchingRecording): bool {
            return $job->recordingId === $matchingRecording->id;
        });
        Queue::assertNotPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($outsideRecording): bool {
            return $job->recordingId === $outsideRecording->id;
        });
    }

    public function test_review_asset_generation_retries_scrub_generation_when_a_preview_is_ready_but_the_sprite_failed(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.170',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream170',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'status' => CameraRecording::STATUS_RECORDED,
            'scheduled_for' => Carbon::create(2026, 4, 7, 11, 30, 0, 'UTC'),
            'started_at' => Carbon::create(2026, 4, 7, 11, 30, 0, 'UTC'),
            'ended_at' => Carbon::create(2026, 4, 7, 11, 31, 0, 'UTC'),
            'relative_path' => 'cameras/'.$camera->id.'/recordings/2026/04/07/atrium-review-assets.mkv',
            'file_size_bytes' => 4096,
            'message' => 'Clip saved.',
        ]);

        $recordingPath = app(CameraStorageService::class)->writableAbsolutePath($recording->relative_path);
        File::ensureDirectoryExists(dirname($recordingPath));
        File::put($recordingPath, 'recorded-segment');

        $recording->forceFill([
            'file_size_bytes' => filesize($recordingPath) ?: null,
        ])->save();

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous')]);

        $reviewAssets = app(RecordingReviewAssetService::class);
        $reviewAssets->generateForRecording($recording);

        $manifestPath = app(CameraStorageService::class)->recordingReviewAssetAbsolutePath($recording->relative_path, 'manifest.json', true);
        $scrubSpriteAbsolutePath = $reviewAssets->scrubSpriteAbsolutePath($recording, true);

        @unlink($scrubSpriteAbsolutePath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertIsArray($manifest);

        file_put_contents($manifestPath, json_encode(array_merge($manifest, [
            'scrub_status' => RecordingReviewAssetService::STATUS_FAILED,
            'scrub_error_message' => 'Unable to generate the scrub preview sprite.',
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $reviewAssets->generateForRecording($recording->fresh());

        $this->assertFileExists($reviewAssets->scrubSpriteAbsolutePath($recording));
        $this->assertSame(
            RecordingReviewAssetService::STATUS_READY,
            app(RecordingReviewAssetService::class)->assetState($recording)['scrub_status']
        );
    }

    public function test_it_uses_the_segment_muxer_for_continuous_recording(): void
    {
        config()->set('queue.default', 'sync');

        Camera::query()->create([
            'name' => 'Atrium',
            'local_ip' => '192.168.1.170',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream170',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('continuous-log-args')]);

        Artisan::call('camera-recordings:tick');

        $argumentLog = storage_path('app/private/test-binaries/ffmpeg-segment-args.log');

        $this->assertFileExists($argumentLog);
        $arguments = (string) file_get_contents($argumentLog);

        $this->assertStringContainsString('-f', $arguments);
        $this->assertStringContainsString('segment', $arguments);
        $this->assertStringContainsString('-segment_time', $arguments);
        $this->assertStringContainsString('-reset_timestamps', $arguments);
        $this->assertStringContainsString('-strftime', $arguments);
        $this->assertStringContainsString('-c', $arguments);
        $this->assertStringContainsString('copy', $arguments);
    }

    public function test_it_skips_motion_recording_when_the_detection_window_is_quiet(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

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

        $this->assertDatabaseCount('camera_recordings', 0);

        $camera = Camera::query()->firstOrFail();

        $this->assertNull($camera->fresh()->recording_last_motion_at);
    }

    public function test_it_ignores_motion_outside_the_selected_mask(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

        Camera::query()->create([
            'name' => 'South Gate',
            'local_ip' => '192.168.1.78',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream8',
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
                    [10, 11],
                    [14, 15],
                ],
            ],
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 0);
    }

    public function test_it_compiles_motion_recordings_with_pre_roll_and_post_trigger_footage(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.analysis_seconds', 3);
        config()->set('recording.motion.post_trigger_seconds', 4);

        $camera = Camera::query()->create([
            'name' => 'Loading Bay',
            'local_ip' => '192.168.1.81',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream10',
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
        $this->assertSame('capture-9', file_get_contents(storage_path('app/private/'.$recording->relative_path)));
        $this->assertSame(9, (int) $recording->started_at?->diffInSeconds($recording->ended_at));
        $this->assertSame(0, (int) $recording->scheduled_for?->diffInSeconds($recording->started_at));
    }

    public function test_it_prefers_camera_specific_motion_timing_over_global_defaults(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.analysis_seconds', 3);
        config()->set('recording.motion.post_trigger_seconds', 4);

        Camera::query()->create([
            'name' => 'Override Bay',
            'local_ip' => '192.168.1.85',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream14',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 25,
            'recording_motion_pre_roll_seconds' => 4,
            'recording_motion_post_trigger_seconds' => 6,
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
        $this->assertSame('capture-13', file_get_contents(storage_path('app/private/'.$recording->relative_path)));
        $this->assertSame(13, (int) $recording->started_at?->diffInSeconds($recording->ended_at));
    }

    public function test_it_uses_the_direct_camera_path_by_default_when_motion_recording_profile_selection_is_automatic(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.analysis_seconds', 3);
        config()->set('recording.motion.post_trigger_seconds', 4);
        config()->set('recording.motion.use_relay_source', false);
        config()->set('mediamtx.auth.reader_user', 'internal-reader');
        config()->set('mediamtx.auth.reader_pass', 'relay-pass');
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:8554');

        $inputLogPath = storage_path('app/private/test-binaries/ffmpeg-last-input.log');
        File::delete($inputLogPath);

        $camera = Camera::query()->create([
            'name' => 'Gaming Room',
            'local_ip' => '192.168.1.69',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'rtsp_profiles' => [
                [
                    'name' => 'mainStream',
                    'uri' => 'rtsp://192.168.1.69:554/stream1',
                ],
                [
                    'name' => 'subStream',
                    'uri' => 'rtsp://192.168.1.69:554/stream2',
                ],
            ],
            'recording_profile_index' => null,
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner-log-input')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertFileExists($inputLogPath);
        $this->assertSame(
            'rtsp://192.168.1.69:554/stream1',
            trim((string) File::get($inputLogPath)),
        );
    }

    public function test_it_uses_the_recording_relay_path_when_enabled_for_motion_recording(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.analysis_seconds', 3);
        config()->set('recording.motion.post_trigger_seconds', 4);
        config()->set('recording.motion.use_relay_source', true);
        config()->set('mediamtx.auth.reader_user', 'internal-reader');
        config()->set('mediamtx.auth.reader_pass', 'relay-pass');
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:8554');

        $inputLogPath = storage_path('app/private/test-binaries/ffmpeg-last-input.log');
        File::delete($inputLogPath);

        $camera = Camera::query()->create([
            'name' => 'Gaming Room',
            'local_ip' => '192.168.1.69',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'rtsp_profiles' => [
                [
                    'name' => 'mainStream',
                    'uri' => 'rtsp://192.168.1.69:554/stream1',
                ],
                [
                    'name' => 'subStream',
                    'uri' => 'rtsp://192.168.1.69:554/stream2',
                ],
            ],
            'recording_profile_index' => null,
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner-log-input')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertFileExists($inputLogPath);
        $this->assertSame(
            'rtsp://internal-reader:relay-pass@127.0.0.1:8554/camera-'.$camera->id.'-recording',
            trim((string) File::get($inputLogPath)),
        );
    }

    public function test_it_keeps_a_motion_clip_when_activity_happens_during_the_monitored_span_after_pre_roll(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.analysis_seconds', 3);
        config()->set('recording.motion.post_trigger_seconds', 4);

        Camera::query()->create([
            'name' => 'Late Motion Bay',
            'local_ip' => '192.168.1.83',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream12',
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-late')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertNotNull($recording->relative_path);
        $this->assertSame('capture-9', file_get_contents(storage_path('app/private/'.$recording->relative_path)));
        $this->assertSame(9, (int) $recording->started_at?->diffInSeconds($recording->ended_at));
    }

    public function test_it_records_the_first_detected_motion_event_even_when_it_occurs_immediately(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.analysis_seconds', 3);
        config()->set('recording.motion.post_trigger_seconds', 4);

        Camera::query()->create([
            'name' => 'Pre-roll Only Bay',
            'local_ip' => '192.168.1.84',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream13',
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-preroll-only')]);

        Artisan::call('camera-recordings:tick');

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertSame('capture-6', file_get_contents(storage_path('app/private/'.$recording->relative_path)));
        $this->assertSame(8, (int) $recording->started_at?->diffInSeconds($recording->ended_at));
    }

    public function test_it_does_not_queue_a_new_motion_recording_while_a_motion_event_is_active(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'West Gate',
            'local_ip' => '192.168.1.82',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream11',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
        ]);

        Cache::put('camera-recordings:motion-event:camera:'.$camera->id, [
            'recording_id' => 999,
            'expires_at' => now()->utc()->addSeconds(60)->toIso8601String(),
        ], 60);

        Artisan::call('camera-recordings:tick');

        $this->assertSame(0, CameraRecording::query()->count());
    }

    public function test_it_does_not_queue_a_new_motion_recording_while_a_pending_row_exists(): void
    {
        config()->set('queue.default', 'sync');

        $camera = Camera::query()->create([
            'name' => 'North Gate',
            'local_ip' => '192.168.1.87',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream16',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
        ]);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => now()->utc()->startOfMinute(),
            'message' => 'Waiting for a worker attempt.',
        ]);

        Artisan::call('camera-recordings:tick');

        $this->assertSame(1, CameraRecording::query()->count());
        $this->assertTrue($recording->fresh()->isPending());
    }

    public function test_it_promotes_a_preferred_motion_row_into_processing_when_motion_is_detected(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.post_trigger_seconds', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.post_trigger_seconds', 4);

        $camera = Camera::query()->create([
            'name' => 'Retry Bay',
            'local_ip' => '192.168.1.86',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream15',
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

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => now()->utc()->startOfMinute(),
            'message' => 'Retrying the same motion event.',
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner')]);

        app(CameraRecordingService::class)->processRecording($recording);

        app(CameraRecordingService::class)->syncMotionRecorder($camera, $recording);

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_PROCESSING, $recording->status);
        $this->assertNotNull($recording->started_at);
        $this->assertNull($recording->relative_path);
    }

    public function test_it_skips_creating_motion_rows_when_the_rolling_recorder_cannot_start(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

        $camera = Camera::query()->create([
            'name' => 'Driveway',
            'local_ip' => '192.168.1.72',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream3',
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('capture-fails')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 0);
    }

    public function test_it_discards_a_stale_pending_motion_segment_instead_of_recovering_it(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

        $camera = Camera::query()->create([
            'name' => 'Lobby',
            'local_ip' => '192.168.1.73',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream4',
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

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => now()->utc()->startOfMinute(),
            'message' => 'Stuck in queue.',
        ]);

        $recording->timestamps = false;
        $recording->forceFill([
            'updated_at' => now()->utc()->subMinutes(15),
        ])->save();
        $recording->timestamps = true;

        $recovered = app(CameraRecordingService::class)->recoverStalePendingRecordings();

        $this->assertSame(0, $recovered);
        $this->assertDatabaseMissing('camera_recordings', [
            'id' => $recording->id,
        ]);
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
        File::put($expiredReviewAssetDirectory.'/preview.mp4', 'preview');
        File::put($expiredReviewAssetDirectory.'/scrub-sprite.jpg', 'sprite');

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
        $previewAbsolutePath = $reviewAssets->previewAbsolutePath($recording, true);
        $scrubSpriteAbsolutePath = $reviewAssets->scrubSpriteAbsolutePath($recording, true);
        File::put($previewAbsolutePath, 'preview');
        File::put($scrubSpriteAbsolutePath, 'sprite');

        Artisan::call('camera-recordings:prune');

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_FAILED, $recording->status);
        $this->assertNull($recording->file_size_bytes);
        $this->assertStringContainsString('missing from active storage', (string) $recording->message);
        $this->assertFileDoesNotExist($previewAbsolutePath);
        $this->assertFileDoesNotExist($scrubSpriteAbsolutePath);
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
        $orphanPreviewAbsolutePath = $storage->recordingReviewAssetAbsolutePath($orphanRelativePath, 'preview.mp4', true);
        File::put($orphanPreviewAbsolutePath, 'preview');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));

        Artisan::call('camera-recordings:orphans');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));
        $this->assertFileExists($trackedAbsolutePath);
        $this->assertFileExists($orphanAbsolutePath);
        $this->assertFileExists($orphanPreviewAbsolutePath);
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
        $this->assertFileDoesNotExist($reviewDirectory.'/preview.mp4');
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
        $orphanPreviewAbsolutePath = $storage->recordingReviewAssetAbsolutePath($orphanRelativePath, 'preview.mp4', true);
        File::put($orphanPreviewAbsolutePath, 'preview');

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertTrue($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));

        Artisan::call('camera-recordings:orphans', ['--purge' => true]);

        $orphanPaths = collect($storage->orphanRecordingFiles())->pluck('relative_path');
        $this->assertFalse($orphanPaths->contains($orphanRelativePath));
        $this->assertFalse($orphanPaths->contains($trackedRelativePath));
        $this->assertFileExists($trackedAbsolutePath);
        $this->assertFileDoesNotExist($orphanAbsolutePath);
        $this->assertFileDoesNotExist($orphanPreviewAbsolutePath);
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

        $rollingMotionScript = static fn (string $profile, bool $logInput = false): string => str_replace(
            ['__PROFILE__', '__LOG_INPUT__'],
            [$profile, $logInput ? '1' : '0'],
            <<<'BASH'
#!/usr/bin/env bash
set -e
profile="__PROFILE__"
log_input="__LOG_INPUT__"

find_input() {
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-i" ]]; then
            printf '%s' "$argument"
            return 0
        fi
        previous="$argument"
    done

    return 1
}

write_segments() {
    php -r '
[$pattern, $stamp, $profile] = array_slice($argv, 1);
$base = DateTimeImmutable::createFromFormat("Ymd_His", $stamp, new DateTimeZone("UTC"));

if (!$base instanceof DateTimeImmutable) {
    $base = new DateTimeImmutable("now", new DateTimeZone("UTC"));
}

$series = [];

for ($index = 0; $index < 20; $index++) {
    $series[$index] = match ($profile) {
        "quiet" => "quiet",
        "preroll-only" => $index < 2 ? "motion" : "quiet",
        default => in_array($index, [4, 5, 6], true) ? "motion" : "quiet",
    };
}

foreach ($series as $index => $label) {
    $timestamp = $base->modify("+{$index} seconds")->format("Ymd_His");
    $path = str_replace("%Y%m%d_%H%M%S", $timestamp, $pattern);
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $label."-".$index);
}
' -- "$1" "$2" "$3"
}

if [[ "$log_input" == "1" ]]; then
    input_log="$(dirname "$0")/ffmpeg-last-input.log"
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-i" && "$argument" == rtsp://* ]]; then
            mkdir -p "$(dirname "$input_log")"
            printf '%s' "$argument" > "$input_log"
            break
        fi
        previous="$argument"
    done
fi

if printf '%s\n' "$@" | grep -qx -- 'rawvideo'; then
    input="$(find_input "$@" || true)"
    contents=""

    if [[ -n "$input" && -f "$input" ]]; then
        contents="$(cat "$input")"
    fi

    if [[ "$contents" == motion* ]]; then
        php -r 'echo str_repeat(chr(0), 16).implode("", array_map(static fn ($value) => chr($value), [255, 255, 0, 0, 255, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]));'
    else
        php -r 'echo str_repeat(chr(0), 32);'
    fi

    exit 0
fi

if printf '%s\n' "$@" | grep -qx -- 'concat'; then
    list_file="$(find_input "$@")"
    output="${!#}"
    count="$(grep -c '^file ' "$list_file" || true)"
    mkdir -p "$(dirname "$output")"
    printf 'capture-%s' "${count:-0}" > "$output"
    exit 0
fi

if printf '%s\n' "$@" | grep -qx -- 'segment'; then
    pattern="${!#}"
    stamp="${FFMPEG_FAKE_NOW_UTC:-$(date -u +%Y%m%d_%H%M%S)}"
    write_segments "$pattern" "$stamp" "$profile"
    exit 0
fi

output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH
        );

        $script = match ($mode) {
            'reject-rw-timeout' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- '-rw_timeout'; then
    printf '%s\n' 'Option rw_timeout not found.' >&2
    exit 1
fi
if printf '%s\n' "$@" | grep -qx -- 'segment'; then
    pattern="${!#}"
    stamp="${FFMPEG_FAKE_NOW_UTC:-$(date -u +%Y%m%d_%H%M%S)}"
    output="${pattern//%Y%m%d_%H%M%S/$stamp}"
    mkdir -p "$(dirname "$output")"
    printf '%s' 'recorded-segment' > "$output"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
            'continuous-log-args' => <<<'BASH'
#!/usr/bin/env bash
set -e
log_path="$(dirname "$0")/ffmpeg-segment-args.log"
printf '%s\n' "$@" >> "$log_path"
printf '%s\n' '---' >> "$log_path"
if printf '%s\n' "$@" | grep -qx -- 'segment'; then
    pattern="${!#}"
    stamp="${FFMPEG_FAKE_NOW_UTC:-$(date -u +%Y%m%d_%H%M%S)}"
    output="${pattern//%Y%m%d_%H%M%S/$stamp}"
    mkdir -p "$(dirname "$output")"
    printf '%s' 'recorded-segment' > "$output"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
            'motion-detected' => $rollingMotionScript('corner'),
            'motion-quiet' => $rollingMotionScript('quiet'),
            'motion-corner' => $rollingMotionScript('corner'),
            'motion-corner-log-input' => $rollingMotionScript('corner', true),
            'motion-late' => $rollingMotionScript('corner'),
            'motion-preroll-only' => $rollingMotionScript('preroll-only'),
            'capture-fails' => <<<'BASH'
#!/usr/bin/env bash
set -e
printf '%s\n' 'simulated capture failure' >&2
exit 1
BASH,
            default => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- 'segment'; then
    pattern="${!#}"
    stamp="${FFMPEG_FAKE_NOW_UTC:-$(date -u +%Y%m%d_%H%M%S)}"
    output="${pattern//%Y%m%d_%H%M%S/$stamp}"
    mkdir -p "$(dirname "$output")"
    printf '%s' 'recorded-segment' > "$output"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
printf '%s' 'recorded-segment' > "$output"
BASH,
        };

            $binaryPath = $binaryDirectory.'/ffmpeg-recording-'.$mode.'-'.md5($script).'.sh';

        File::put($binaryPath, $script);
        chmod($binaryPath, 0755);

        return $binaryPath;
    }
}