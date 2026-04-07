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
use Illuminate\Support\Facades\Cache;
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

    public function test_it_uses_the_default_recording_relay_path_when_motion_recording_profile_selection_is_automatic(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.pre_roll_seconds', 2);
        config()->set('recording.motion.analysis_seconds', 3);
        config()->set('recording.motion.post_trigger_seconds', 4);
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

    public function test_it_ignores_motion_that_only_appears_inside_the_pre_roll_context(): void
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

        $this->assertDatabaseCount('camera_recordings', 0);
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

    public function test_it_allows_the_same_motion_recording_to_resume_when_it_already_owns_the_active_state(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

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

        Cache::put('camera-recordings:motion-event:camera:'.$camera->id, [
            'recording_id' => $recording->id,
            'expires_at' => now()->utc()->addSeconds(60)->toIso8601String(),
        ], 60);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner')]);

        app(CameraRecordingService::class)->processRecording($recording);

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertNotNull($recording->relative_path);
        $this->assertFileExists(storage_path('app/private/'.$recording->relative_path));
    }

    public function test_it_marks_the_segment_failed_when_capture_errors_out(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

        Camera::query()->create([
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

        $recording = CameraRecording::query()->firstOrFail();

        $this->assertSame(CameraRecording::STATUS_FAILED, $recording->status);
        $this->assertNull($recording->relative_path);
        $this->assertStringContainsString('simulated capture failure', (string) $recording->message);
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

        $reviewDirectory = dirname(app(CameraStorageService::class)->recordingReviewAssetAbsolutePath($recording->relative_path, '.', true));
        File::put($reviewDirectory.'/preview.mp4', 'preview');
        File::put($reviewDirectory.'/poster.jpg', 'poster');

        Artisan::call('camera-recordings:prune');

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_FAILED, $recording->status);
        $this->assertNull($recording->file_size_bytes);
        $this->assertStringContainsString('missing from active storage', (string) $recording->message);
        $this->assertDirectoryDoesNotExist($reviewDirectory);
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
            'motion-detected' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- 'rawvideo'; then
    php -r 'echo str_repeat(chr(0), 16).str_repeat(chr(255), 16);'
    exit 0
fi
if printf '%s\n' "$@" | grep -qx -- 'concat'; then
    list_file=""
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-i" ]]; then
            list_file="$argument"
            break
        fi
        previous="$argument"
    done
    output="${!#}"
    mkdir -p "$(dirname "$output")"
    : > "$output"
    while IFS= read -r line; do
        path="${line#file }"
        path="${path#\'}"
        path="${path%\'}"
        cat "$path" >> "$output"
    done < "$list_file"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
duration="capture-output"
previous=""
for argument in "$@"; do
    if [[ "$previous" == "-t" ]]; then
        duration="capture-$argument"
        break
    fi
    previous="$argument"
done
printf '%s' "$duration" > "$output"
BASH,
            'motion-quiet' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- 'rawvideo'; then
    php -r 'echo str_repeat(chr(0), 32);'
    exit 0
fi
if printf '%s\n' "$@" | grep -qx -- 'concat'; then
    list_file=""
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-i" ]]; then
            list_file="$argument"
            break
        fi
        previous="$argument"
    done
    output="${!#}"
    mkdir -p "$(dirname "$output")"
    : > "$output"
    while IFS= read -r line; do
        path="${line#file }"
        path="${path#\'}"
        path="${path%\'}"
        cat "$path" >> "$output"
    done < "$list_file"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
duration="capture-output"
previous=""
for argument in "$@"; do
    if [[ "$previous" == "-t" ]]; then
        duration="capture-$argument"
        break
    fi
    previous="$argument"
done
printf '%s' "$duration" > "$output"
BASH,
            'motion-corner' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- 'rawvideo'; then
    php -r 'echo str_repeat(chr(0), 16).implode("", array_map(static fn ($value) => chr($value), [255, 255, 0, 0, 255, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]));'
    exit 0
fi
if printf '%s\n' "$@" | grep -qx -- 'concat'; then
    list_file=""
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-i" ]]; then
            list_file="$argument"
            break
        fi
        previous="$argument"
    done
    output="${!#}"
    mkdir -p "$(dirname "$output")"
    : > "$output"
    while IFS= read -r line; do
        path="${line#file }"
        path="${path#\'}"
        path="${path%\'}"
        cat "$path" >> "$output"
    done < "$list_file"
    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
duration="capture-output"
previous=""
for argument in "$@"; do
    if [[ "$previous" == "-t" ]]; then
        duration="capture-$argument"
        break
    fi
    previous="$argument"
done
printf '%s' "$duration" > "$output"
BASH,
            'motion-corner-log-input' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- 'rawvideo'; then
    php -r 'echo str_repeat(chr(0), 16).implode("", array_map(static fn ($value) => chr($value), [255, 255, 0, 0, 255, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]));'
    exit 0
fi
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
output="${!#}"
mkdir -p "$(dirname "$output")"
duration="capture-output"
previous=""
for argument in "$@"; do
    if [[ "$previous" == "-t" ]]; then
        duration="capture-$argument"
        break
    fi
    previous="$argument"
done
printf '%s' "$duration" > "$output"
BASH,
            'motion-late' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- 'rawvideo'; then
    duration=""
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-t" ]]; then
            duration="$argument"
            break
        fi
        previous="$argument"
    done

    if [[ "$duration" == "7" ]]; then
        php -r 'echo str_repeat(chr(0), 16).str_repeat(chr(0), 16).str_repeat(chr(0), 16).implode("", array_map(static fn ($value) => chr($value), [255, 255, 0, 0, 255, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0]));'
    else
        php -r 'echo str_repeat(chr(0), 64);'
    fi

    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
duration="capture-output"
previous=""
for argument in "$@"; do
    if [[ "$previous" == "-t" ]]; then
        duration="capture-$argument"
        break
    fi
    previous="$argument"
done
printf '%s' "$duration" > "$output"
BASH,
            'motion-preroll-only' => <<<'BASH'
#!/usr/bin/env bash
set -e
if printf '%s\n' "$@" | grep -qx -- 'rawvideo'; then
    saw_offset="false"
    previous=""
    for argument in "$@"; do
        if [[ "$previous" == "-ss" && "$argument" == "2" ]]; then
            saw_offset="true"
            break
        fi
        previous="$argument"
    done

    if [[ "$saw_offset" == "true" ]]; then
        php -r 'echo str_repeat(chr(0), 64);'
    else
        php -r 'echo str_repeat(chr(0), 16).implode("", array_map(static fn ($value) => chr($value), [255, 255, 0, 0, 255, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0])).str_repeat(chr(0), 32);'
    fi

    exit 0
fi
output="${!#}"
mkdir -p "$(dirname "$output")"
duration="capture-output"
previous=""
for argument in "$@"; do
    if [[ "$previous" == "-t" ]]; then
        duration="capture-$argument"
        break
    fi
    previous="$argument"
done
printf '%s' "$duration" > "$output"
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