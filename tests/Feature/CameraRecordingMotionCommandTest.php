<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\CameraRecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\BuildsFakeRecordingFfmpegBinary;
use Tests\TestCase;

class CameraRecordingMotionCommandTest extends TestCase
{
    use BuildsFakeRecordingFfmpegBinary;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
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
}
