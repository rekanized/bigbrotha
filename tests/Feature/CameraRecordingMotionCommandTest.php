<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\CameraRecordingService;
use App\Services\MotionRecordingSegmenterService;
use App\Services\RecordingMotionDetectorService;
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

    public function test_it_ignores_single_frame_refresh_spikes_in_hevc_motion_segments(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.persistence_window_frames', 2);

        Camera::query()->create([
            'name' => 'HEVC Yard',
            'local_ip' => '192.168.1.88',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream17',
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-refresh-glitch')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 0);

        $camera = Camera::query()->firstOrFail();

        $this->assertNull($camera->fresh()->recording_last_motion_at);
    }

    public function test_it_still_detects_brief_localized_motion_segments(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.persistence_window_frames', 2);

        Camera::query()->create([
            'name' => 'Front Walkway',
            'local_ip' => '192.168.1.89',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream18',
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-brief-local')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 1);
        $this->assertNotNull(Camera::query()->firstOrFail()->fresh()->recording_last_motion_at);
    }

    public function test_it_ignores_isolated_single_pixel_changes_inside_the_mask(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.isolated_pixel_radius', 1);

        Camera::query()->create([
            'name' => 'Office Door',
            'local_ip' => '192.168.1.90',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream19',
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-isolated-pixel')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 0);
        $this->assertNull(Camera::query()->firstOrFail()->fresh()->recording_last_motion_at);
    }

    public function test_it_ignores_two_pixel_adjacent_changes_when_three_connected_pixels_are_required(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.isolated_pixel_radius', 1);
        config()->set('recording.motion.minimum_cluster_pixels', 3);

        Camera::query()->create([
            'name' => 'Loading Bay Pair Noise',
            'local_ip' => '192.168.1.92',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream21',
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-adjacent-pair')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 0);
        $this->assertNull(Camera::query()->firstOrFail()->fresh()->recording_last_motion_at);
    }

    public function test_it_weights_dense_motion_clusters_more_than_sparse_changes(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.cluster_bonus_min_size', 3);
        config()->set('recording.motion.cluster_bonus_multiplier', 2);

        Camera::query()->create([
            'name' => 'Clustered Motion Yard',
            'local_ip' => '192.168.1.91',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream20',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 25,
            'recording_motion_trigger_pixels' => 4,
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-clustered-triplet')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 1);
        $this->assertNotNull(Camera::query()->firstOrFail()->fresh()->recording_last_motion_at);
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

    public function test_it_uses_the_canonical_source_relay_when_motion_recording_profile_selection_is_automatic(): void
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
            'recording_rtsp_path' => '/stream1',
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
            'rtsp://internal-reader:relay-pass@127.0.0.1:8554/camera-'.$camera->id.'-source',
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
            'recording_rtsp_path' => '/stream1',
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
            'rtsp://internal-reader:relay-pass@127.0.0.1:8554/camera-'.$camera->id.'-source',
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

    public function test_it_discards_an_unreadable_buffered_motion_segment_and_continues_processing(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Yard',
            'local_ip' => '192.168.1.74',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream5',
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

        $segmentDirectory = storage_path('app/private/motion-recorders/camera-'.$camera->id.'/segments');
        File::ensureDirectoryExists($segmentDirectory);

        $corruptPath = $segmentDirectory.'/20260410_113344-buffer.mkv';
        $healthyPath = $segmentDirectory.'/20260410_113345-buffer.mkv';

        File::put($corruptPath, 'not-a-real-mkv');
        File::put($healthyPath, 'healthy-placeholder');

        $segmenter = \Mockery::mock(MotionRecordingSegmenterService::class);
        $segmenter->shouldReceive('syncCamera')
            ->once()
            ->andReturn([
                'started' => false,
                'running' => false,
                'pid' => null,
            ]);
        $segmenter->shouldReceive('closedSegmentsSince')
            ->once()
            ->andReturn([
                [
                    'path' => $corruptPath,
                    'started_at' => now()->utc()->setDate(2026, 4, 10)->setTime(11, 33, 44),
                    'ended_at' => now()->utc()->setDate(2026, 4, 10)->setTime(11, 33, 45),
                ],
                [
                    'path' => $healthyPath,
                    'started_at' => now()->utc()->setDate(2026, 4, 10)->setTime(11, 33, 45),
                    'ended_at' => now()->utc()->setDate(2026, 4, 10)->setTime(11, 33, 46),
                ],
            ]);
        $segmenter->shouldReceive('pruneSegments')
            ->once()
            ->andReturn(1);

        $detector = \Mockery::mock(RecordingMotionDetectorService::class);
        $detector->shouldReceive('detectClip')
            ->once()
            ->with($camera, $corruptPath)
            ->andThrow(new \RuntimeException('Unable to evaluate motion for this camera. [matroska,webm @ 0x1] EBML header parsing failed Error opening input: Invalid data found when processing input Error opening input file '.$corruptPath));
        $detector->shouldReceive('detectClip')
            ->once()
            ->with($camera, $healthyPath)
            ->andReturn([
                'detected' => false,
                'activity_ratio' => 0.0,
                'changed_pixels' => 0,
                'selected_pixels' => 4,
                'frame_count' => 3,
            ]);

        $this->app->instance(MotionRecordingSegmenterService::class, $segmenter);
        $this->app->instance(RecordingMotionDetectorService::class, $detector);

        $result = app(CameraRecordingService::class)->syncMotionRecorder($camera);

        $this->assertSame([
            'started' => false,
            'finalized' => 0,
            'running' => false,
        ], $result);
        $this->assertFileDoesNotExist($corruptPath);
        $this->assertFileExists($healthyPath);
    }

    public function test_it_prunes_old_motion_buffer_segments_after_a_retryable_staged_clip_exists(): void
    {
        $now = \Illuminate\Support\Carbon::create(2026, 4, 10, 19, 5, 0, 'UTC');

        $this->travelTo($now);

        try {
            config()->set('recording.motion.idle_buffer_seconds', 180);

            $camera = Camera::query()->create([
                'name' => 'Loading Dock',
                'local_ip' => '192.168.1.84',
                'rtsp_port' => 554,
                'rtsp_path' => '/stream12',
                'supports_onvif' => false,
                'supports_rtsp' => true,
                'is_enabled' => true,
                'recording_mode' => Camera::RECORDING_MODE_MOTION,
                'recording_retention_days' => 1,
                'motion_sensitivity' => 25,
            ]);

            $scheduledFor = \Illuminate\Support\Carbon::create(2026, 4, 10, 15, 21, 53, 'UTC');
            $eventStartedAt = $scheduledFor->copy();
            $finalizeAfter = $scheduledFor->copy()->addSeconds(40);
            $coveredUntil = $scheduledFor->copy()->addSeconds(44);

            $recording = CameraRecording::query()->create([
                'camera_id' => $camera->id,
                'capture_mode' => Camera::RECORDING_MODE_MOTION,
                'status' => CameraRecording::STATUS_PROCESSING,
                'scheduled_for' => $scheduledFor,
                'started_at' => $eventStartedAt,
                'message' => 'Unable to verify the uploaded file on the active camera storage disk.',
            ]);

            \App\Models\CameraMotionState::query()->create([
                'camera_id' => $camera->id,
                'active_recording_id' => $recording->id,
                'event_started_at' => $eventStartedAt,
                'last_motion_at' => $scheduledFor->copy()->addSeconds(20),
                'finalize_after' => $finalizeAfter,
            ]);

            $storage = app(\App\Services\CameraStorageService::class);
            $fileName = $scheduledFor->format('Ymd_His').'-motion.'.config('recording.extension', 'mkv');
            $absolutePath = $storage->recordingAbsolutePath($camera, $scheduledFor, $fileName);
            $relativePath = $storage->recordingRelativePathFromAbsolute($absolutePath);

            File::ensureDirectoryExists(dirname($absolutePath));
            File::put($absolutePath, 'stitched-motion-event');
            File::put($absolutePath.'.motion-ready.json', json_encode([
                'window_start' => $eventStartedAt->toIso8601String(),
                'window_end' => $finalizeAfter->toIso8601String(),
                'covered_until' => $coveredUntil->toIso8601String(),
                'generated_at' => $now->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            $storage = \Mockery::mock(\App\Services\CameraStorageService::class, [app(\App\Services\ApplicationSettingsService::class)])
                ->makePartial();
            $storage->shouldReceive('finalizeStagedWrite')
                ->once()
                ->with($relativePath, $absolutePath)
                ->andThrow(new \RuntimeException('Unable to verify the uploaded file on the active camera storage disk.'));
            $this->app->instance(\App\Services\CameraStorageService::class, $storage);

            $segmenter = \Mockery::mock(MotionRecordingSegmenterService::class);
            $segmenter->shouldReceive('syncCamera')
                ->once()
                ->andReturn([
                    'started' => false,
                    'running' => false,
                    'pid' => null,
                ]);
            $segmenter->shouldReceive('closedSegmentsSince')
                ->once()
                ->andReturn([]);
            $segmenter->shouldReceive('segmentsForWindow')->never();
            $segmenter->shouldReceive('pruneSegments')
                ->once()
                ->with(
                    \Mockery::on(fn (Camera $resolvedCamera): bool => $resolvedCamera->is($camera)),
                    \Mockery::on(fn ($keepFrom): bool => $keepFrom instanceof \Illuminate\Support\Carbon
                        && $keepFrom->equalTo($now->copy()->subSeconds(180))),
                    false,
                )
                ->andReturn(0);
            $this->app->instance(MotionRecordingSegmenterService::class, $segmenter);

            $result = app(CameraRecordingService::class)->syncMotionRecorder($camera);

            $this->assertSame([
                'started' => false,
                'finalized' => 0,
                'running' => false,
            ], $result);
            $this->assertFileExists($absolutePath);
            $this->assertFileExists($absolutePath.'.motion-ready.json');
            $this->assertDatabaseHas('camera_motion_states', [
                'camera_id' => $camera->id,
                'active_recording_id' => $recording->id,
            ]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_it_uses_the_configured_recording_path_when_resolving_motion_recording_source(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.168.1.69',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'recording_rtsp_path' => '/stream2',
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'mainStream',
                        'uri' => 'rtsp://192.168.1.69:554/stream1',
                        'path' => '/stream1',
                    ],
                    [
                        'name' => 'subStream',
                        'uri' => 'rtsp://192.168.1.69:554/stream2',
                        'path' => '/stream2',
                    ],
                ],
            ],
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

        $source = app(CameraRecordingService::class)->resolveRecordingSource($camera);

        $this->assertNotNull($source);
        $this->assertSame(1, $source['index']);
        $this->assertSame('rtsp://192.168.1.69:554/stream2', $source['authenticated_uri']);
    }

    public function test_it_uses_the_resolved_source_index_for_motion_source_paths_when_selection_is_automatic(): void
    {
        config()->set('mediamtx.auth.reader_user', 'internal-reader');
        config()->set('mediamtx.auth.reader_pass', 'relay-pass');
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:8554');

        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.168.1.69',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'recording_rtsp_path' => '/stream2',
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'mainStream',
                        'uri' => 'rtsp://192.168.1.69:554/stream1',
                        'path' => '/stream1',
                    ],
                    [
                        'name' => 'subStream',
                        'uri' => 'rtsp://192.168.1.69:554/stream2',
                        'path' => '/stream2',
                    ],
                ],
            ],
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

        $segmenter = \Mockery::mock(MotionRecordingSegmenterService::class);
        $segmenter->shouldReceive('syncCamera')
            ->once()
            ->with(
                \Mockery::on(fn (Camera $resolvedCamera): bool => $resolvedCamera->is($camera)),
                \Mockery::on(fn (array $source): bool => ($source['index'] ?? null) === 1
                    && ($source['authenticated_uri'] ?? null) === 'rtsp://internal-reader:relay-pass@127.0.0.1:8554/camera-'.$camera->id.'-source-profile-1'
                    && ($source['transport'] ?? null) === 'tcp'),
            )
            ->andReturn([
                'started' => true,
                'running' => true,
                'pid' => 1234,
            ]);
        $segmenter->shouldReceive('closedSegmentsSince')
            ->once()
            ->andReturn([]);
        $segmenter->shouldReceive('pruneSegments')
            ->once()
            ->with(
                \Mockery::on(fn (Camera $resolvedCamera): bool => $resolvedCamera->is($camera)),
                \Mockery::type(\Illuminate\Support\Carbon::class),
                true,
            )
            ->andReturn(0);

        $this->app->instance(MotionRecordingSegmenterService::class, $segmenter);

        $result = app(CameraRecordingService::class)->syncMotionRecorder($camera);

        $this->assertSame([
            'started' => true,
            'finalized' => 0,
            'running' => true,
        ], $result);
    }

    public function test_it_uses_the_shared_source_relay_for_motion_capture_when_the_recording_source_matches_the_live_profile(): void
    {
        config()->set('mediamtx.auth.reader_user', 'internal-reader');
        config()->set('mediamtx.auth.reader_pass', 'relay-pass');
        config()->set('mediamtx.rtsp.internal_base_url', 'rtsp://127.0.0.1:8554');

        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.168.1.69',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream2',
            'recording_rtsp_path' => '/stream2',
            'metadata' => [
                'rtsp_profiles' => [
                    [
                        'name' => 'mainStream',
                        'uri' => 'rtsp://192.168.1.69:554/stream1',
                        'path' => '/stream1',
                        'resolution' => '1920x1080',
                    ],
                    [
                        'name' => 'subStream',
                        'uri' => 'rtsp://192.168.1.69:554/stream2',
                        'path' => '/stream2',
                        'resolution' => '1280x720',
                        'probe_status' => 'Healthy',
                    ],
                ],
            ],
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

        $segmenter = \Mockery::mock(MotionRecordingSegmenterService::class);
        $segmenter->shouldReceive('syncCamera')
            ->once()
            ->with(
                \Mockery::on(fn (Camera $resolvedCamera): bool => $resolvedCamera->is($camera)),
                \Mockery::on(fn (array $source): bool => ($source['index'] ?? null) === 1
                    && ($source['authenticated_uri'] ?? null) === 'rtsp://internal-reader:relay-pass@127.0.0.1:8554/camera-'.$camera->id.'-source-profile-1'
                    && ($source['transport'] ?? null) === 'tcp'),
            )
            ->andReturn([
                'started' => true,
                'running' => true,
                'pid' => 1234,
            ]);
        $segmenter->shouldReceive('closedSegmentsSince')
            ->once()
            ->andReturn([]);
        $segmenter->shouldReceive('pruneSegments')
            ->once()
            ->with(
                \Mockery::on(fn (Camera $resolvedCamera): bool => $resolvedCamera->is($camera)),
                \Mockery::type(\Illuminate\Support\Carbon::class),
                true,
            )
            ->andReturn(0);

        $this->app->instance(MotionRecordingSegmenterService::class, $segmenter);

        $result = app(CameraRecordingService::class)->syncMotionRecorder($camera);

        $this->assertSame([
            'started' => true,
            'finalized' => 0,
            'running' => true,
        ], $result);
    }

    public function test_it_rolls_an_active_motion_event_into_a_new_clip_once_it_reaches_the_stitch_limit(): void
    {
        config()->set('recording.motion.pre_roll_seconds', 8);
        config()->set('recording.motion.post_trigger_seconds', 20);
        config()->set('recording.motion.max_stitched_seconds', 180);
        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner')]);

        $camera = Camera::query()->create([
            'name' => 'Kitchen',
            'local_ip' => '192.168.1.75',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream6',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 25,
        ]);

        $eventStartedAt = \Illuminate\Support\Carbon::create(2026, 4, 10, 15, 0, 0, 'UTC')->startOfSecond();
        $rolloverStartedAt = $eventStartedAt->copy()->addSeconds(180);
        $previousSegment = [
            'path' => storage_path('app/private/motion-recorders/camera-'.$camera->id.'/segments/20260410_145956-buffer.mkv'),
            'started_at' => $rolloverStartedAt->copy()->subSeconds(4),
            'ended_at' => $rolloverStartedAt->copy(),
        ];
        $currentSegment = [
            'path' => storage_path('app/private/motion-recorders/camera-'.$camera->id.'/segments/20260410_150300-buffer.mkv'),
            'started_at' => $rolloverStartedAt->copy(),
            'ended_at' => $rolloverStartedAt->copy()->addSeconds(4),
        ];

        $activeRecording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_PROCESSING,
            'scheduled_for' => $eventStartedAt,
            'started_at' => $eventStartedAt,
            'message' => 'Motion is still active in the rolling segment buffer.',
        ]);

        \App\Models\CameraMotionState::query()->create([
            'camera_id' => $camera->id,
            'active_recording_id' => $activeRecording->id,
            'event_started_at' => $eventStartedAt,
            'last_motion_at' => $rolloverStartedAt->copy()->subSeconds(4),
            'finalize_after' => $rolloverStartedAt->copy()->addSeconds(20),
        ]);

        $storage = app(\App\Services\CameraStorageService::class);
        $stagedPath = $storage->recordingAbsolutePath(
            $camera,
            $eventStartedAt,
            $eventStartedAt->format('Ymd_His').'-motion.'.config('recording.extension', 'mkv'),
        );
        $relativePath = $storage->recordingRelativePathFromAbsolute($stagedPath);

        File::ensureDirectoryExists(dirname($previousSegment['path']));
        File::put($previousSegment['path'], 'motion-44');

        $segmenter = \Mockery::mock(MotionRecordingSegmenterService::class);
        $segmenter->shouldReceive('syncCamera')
            ->once()
            ->andReturn([
                'started' => false,
                'running' => false,
                'pid' => null,
            ]);
        $segmenter->shouldReceive('closedSegmentsSince')
            ->once()
            ->andReturn([$currentSegment]);
        $segmenter->shouldReceive('segmentsForWindow')
            ->twice()
            ->with(\Mockery::type(Camera::class), \Mockery::type(\Illuminate\Support\Carbon::class), \Mockery::type(\Illuminate\Support\Carbon::class), false)
            ->andReturn([$previousSegment], []);
        $segmenter->shouldReceive('pruneSegments')
            ->once()
            ->with(
                \Mockery::on(fn (Camera $resolvedCamera): bool => $resolvedCamera->is($camera)),
                \Mockery::on(fn ($keepFrom): bool => $keepFrom instanceof \Illuminate\Support\Carbon
                    && $keepFrom->equalTo($rolloverStartedAt)),
                false,
            )
            ->andReturn(0);
        $this->app->instance(MotionRecordingSegmenterService::class, $segmenter);

        $detector = \Mockery::mock(RecordingMotionDetectorService::class);
        $detector->shouldReceive('detectClip')
            ->once()
            ->with($camera, $currentSegment['path'])
            ->andReturn([
                'detected' => true,
                'activity_ratio' => 0.42,
                'changed_pixels' => 10,
                'selected_pixels' => 16,
                'frame_count' => 3,
            ]);
        $this->app->instance(RecordingMotionDetectorService::class, $detector);

        $result = app(CameraRecordingService::class)->syncMotionRecorder($camera);

        $activeRecording->refresh();
        $replacementRecording = CameraRecording::query()
            ->where('camera_id', $camera->id)
            ->whereKeyNot($activeRecording->id)
            ->sole();
        $motionState = \App\Models\CameraMotionState::query()->where('camera_id', $camera->id)->firstOrFail();

        $this->assertSame([
            'started' => true,
            'finalized' => 1,
            'running' => false,
        ], $result);
        $this->assertSame(CameraRecording::STATUS_RECORDED, $activeRecording->status);
        $this->assertSame($relativePath, $activeRecording->relative_path);
        $this->assertTrue($activeRecording->ended_at?->equalTo($rolloverStartedAt));
        $this->assertSame('capture-1', file_get_contents($stagedPath));
        $this->assertSame(CameraRecording::STATUS_PROCESSING, $replacementRecording->status);
        $this->assertTrue($replacementRecording->started_at?->equalTo($rolloverStartedAt));
        $this->assertNull($replacementRecording->relative_path);
        $this->assertSame($replacementRecording->id, $motionState->active_recording_id);
        $this->assertTrue($motionState->event_started_at?->equalTo($rolloverStartedAt));
        $this->assertTrue($motionState->finalize_after?->equalTo($currentSegment['ended_at']->copy()->addSeconds(20)));

        Queue::assertPushed(\App\Jobs\GenerateRecordingReviewAssetsJob::class, function (\App\Jobs\GenerateRecordingReviewAssetsJob $job) use ($activeRecording): bool {
            return $job->recordingId === $activeRecording->id;
        });
    }
}
