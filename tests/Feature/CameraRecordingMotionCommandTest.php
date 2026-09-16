<?php

namespace Tests\Feature;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Models\Camera;
use App\Models\CameraMotionState;
use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use App\Services\CameraRecordingService;
use App\Services\CameraStorageService;
use App\Services\MotionRecordingSegmenterService;
use App\Services\RecordingMotionDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
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

    public function test_motion_sync_skips_another_process_that_is_still_finalizing(): void
    {
        $camera = Camera::query()->create(['name' => 'Locked', 'local_ip' => '192.168.1.82', 'recording_mode' => 'motion']);
        $directory = storage_path('app/private/motion-recorders');
        File::ensureDirectoryExists($directory);
        $handle = fopen($directory.'/camera-'.$camera->id.'.sync.lock', 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $result = app(CameraRecordingService::class)->syncMotionRecorder($camera);
            $this->assertSame(0, $result['finalized']);
            $this->assertDatabaseCount('camera_recordings', 0);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function test_decoder_log_messages_without_video_never_trigger_motion(): void
    {
        $binary = storage_path('app/private/test-binaries/motion-log-only.sh');
        File::ensureDirectoryExists(dirname($binary));
        File::put($binary, "#!/bin/sh\nprintf 'showinfo: diagnostic only\\n' >&2\n");
        chmod($binary, 0755);
        config()->set('ffmpeg.ffmpeg.binaries', [$binary]);
        $camera = new Camera(['recording_motion_trigger_pixels' => 1]);

        $motion = app(RecordingMotionDetectorService::class)->detectClip($camera, '/unused.mkv');

        $this->assertFalse($motion['detected']);
        $this->assertSame(0, $motion['frame_count']);
        $this->assertSame([], $motion['changed_indexes']);
    }

    public function test_latest_only_evaluation_retains_full_window_artifact_and_motion_results(): void
    {
        $quiet = str_repeat(chr(0), 16);
        $local = chr(255).chr(255).chr(0).chr(0).chr(255).chr(255).str_repeat(chr(0), 10);
        $refresh = str_repeat(chr(255), 16);
        $frames = [$quiet, $local, $quiet, $refresh, $quiet, $local, $local, $quiet];
        $camera = new Camera([
            'recording_motion_mask' => ['grid_width' => 4, 'grid_height' => 4, 'runs' => [[0, 15]]],
            'recording_motion_trigger_pixels' => 3,
        ]);
        $detector = app(RecordingMotionDetectorService::class);
        for ($count = 3; $count <= count($frames); $count++) {
            $output = implode('', array_slice($frames, 0, $count));
            $full = $detector->analyzeFrames($camera, $output);
            $current = $detector->analyzeFrames($camera, $output, true, true);
            $this->assertSame($full['latest'], $current['latest']);
            $this->assertSame($current['latest']['effective_trigger_pixels'], $current['changed_pixels']);
        }
    }

    public function test_real_ffmpeg_keeps_local_movement_and_returns_to_quiet(): void
    {
        $quiet = str_repeat(chr(40), 32 * 18);
        $moving = $quiet;
        for ($y = 3; $y < 11; $y++) {
            for ($x = 3; $x < 11; $x++) {
                $moving[$y * 32 + $x] = chr(220);
            }
        }
        $raw = storage_path('app/private/real-motion.gray');
        $clip = storage_path('app/private/real-motion.mkv');
        File::put($raw, $quiet.$moving.$quiet.$quiet.$quiet.$quiet);
        $encode = new Process([
            '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-f', 'rawvideo', '-pixel_format', 'gray',
            '-video_size', '32x18', '-framerate', '3', '-i', $raw, '-c:v', 'ffv1', '-threads', '1', $clip,
        ]);
        $encode->mustRun();
        config()->set('ffmpeg.ffmpeg.binaries', ['/usr/bin/ffmpeg']);
        config()->set('recording.motion.analysis_fps', 3);
        $camera = new Camera([
            'recording_motion_trigger_pixels' => 10,
            'recording_motion_mask' => ['grid_width' => 32, 'grid_height' => 18, 'runs' => [[0, 575]]],
        ]);
        $detector = app(RecordingMotionDetectorService::class);
        $preview = $detector->detectPreviewClip($camera, $clip);
        $recording = $detector->detectClip($camera, $clip);

        $this->assertTrue($preview['detected']);
        $this->assertTrue($recording['detected']);
        $this->assertSame(64, count($preview['changed_indexes']));
        $this->assertSame(188, $preview['changed_pixels']);
        $this->assertSame($recording['changed_pixels'], $preview['changed_pixels']);
        $this->assertSame(6, $preview['frame_count']);
        $this->assertFalse($preview['latest']['detected']);
        $this->assertSame('quiet', $preview['latest']['reason']);
        $this->assertSame([], $preview['latest']['changed_indexes']);
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

    public function test_motion_segmenter_flushes_packets_into_the_active_matroska_segment(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

        Camera::query()->create([
            'name' => 'Live motion preview',
            'local_ip' => '192.168.1.67',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 60,
        ]);

        $argumentLog = storage_path('app/private/test-binaries/ffmpeg-motion-args.log');
        File::delete($argumentLog);
        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner-log-args')]);

        Artisan::call('camera-recordings:tick');

        $this->assertFileExists($argumentLog);
        $arguments = (string) File::get($argumentLog);

        $this->assertStringContainsString("-flush_packets\n1\n", $arguments);
        $this->assertStringContainsString("-segment_format_options\nflush_packets=1:cluster_time_limit=250\n", $arguments);
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

    public function test_it_ignores_persistent_global_luminance_shifts(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

        Camera::query()->create([
            'name' => 'Exposure Switching Yard',
            'local_ip' => '192.168.1.93',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream22',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 25,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-global-luminance-shift')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 0);
        $this->assertNull(Camera::query()->firstOrFail()->fresh()->recording_last_motion_at);
    }

    public function test_it_ignores_persistent_widespread_pixel_refreshes(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);

        Camera::query()->create([
            'name' => 'Block Refresh Yard',
            'local_ip' => '192.168.1.94',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream23',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'motion_sensitivity' => 25,
        ]);

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-widespread-refresh')]);

        Artisan::call('camera-recordings:tick');

        $this->assertDatabaseCount('camera_recordings', 0);
        $this->assertNull(Camera::query()->firstOrFail()->fresh()->recording_last_motion_at);
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

    public function test_detector_exposes_the_filtered_activity_cells_used_by_the_motion_editor(): void
    {
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-brief-local')]);

        $camera = Camera::query()->create([
            'name' => 'Backyard',
            'local_ip' => '192.168.1.89',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream18',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'recording_motion_trigger_pixels' => 3,
            'recording_motion_mask' => [
                'version' => 1,
                'grid_width' => 4,
                'grid_height' => 4,
                'selected_pixels' => 16,
                'runs' => [[0, 15]],
            ],
        ]);
        $segmentPath = storage_path('app/private/test-motion-editor-buffer.mkv');
        File::ensureDirectoryExists(dirname($segmentPath));
        File::put($segmentPath, 'brief-motion-0');

        try {
            $motion = app(RecordingMotionDetectorService::class)->detectClip($camera, $segmentPath);
        } finally {
            File::delete($segmentPath);
        }

        $this->assertTrue($motion['detected']);
        $this->assertSame(8, $motion['changed_pixels']);
        $this->assertEqualsCanonicalizing([0, 1, 4, 5], $motion['changed_indexes']);
    }

    public function test_live_preview_waits_for_future_frames_before_exposing_a_transition(): void
    {
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.persistence_window_frames', 2);
        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-brief-local')]);

        $camera = Camera::query()->create([
            'name' => 'Backyard live preview',
            'local_ip' => '192.168.1.90',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream19',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'recording_motion_trigger_pixels' => 3,
            'recording_motion_mask' => [
                'version' => 1,
                'grid_width' => 4,
                'grid_height' => 4,
                'selected_pixels' => 16,
                'runs' => [[0, 15]],
            ],
        ]);
        $segmentPath = storage_path('app/private/test-motion-editor-live-lookahead.mkv');
        File::ensureDirectoryExists(dirname($segmentPath));
        File::put($segmentPath, 'unconfirmed-motion-live');

        try {
            $closedSegment = app(RecordingMotionDetectorService::class)->detectClip($camera, $segmentPath);
            $liveSnapshot = app(RecordingMotionDetectorService::class)->detectPreviewClip($camera, $segmentPath);
        } finally {
            File::delete($segmentPath);
        }

        $this->assertTrue($closedSegment['detected']);
        $this->assertFalse($liveSnapshot['detected']);
        $this->assertSame(2, $liveSnapshot['frame_count']);
        $this->assertSame([], $liveSnapshot['changed_indexes']);
    }

    public function test_live_preview_exposes_motion_as_soon_as_lookahead_is_available(): void
    {
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.persistence_window_frames', 2);
        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-brief-local')]);

        $camera = Camera::query()->create([
            'name' => 'Backyard confirmed preview',
            'local_ip' => '192.168.1.91',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream20',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'recording_motion_trigger_pixels' => 3,
            'recording_motion_mask' => [
                'version' => 1,
                'grid_width' => 4,
                'grid_height' => 4,
                'selected_pixels' => 16,
                'runs' => [[0, 15]],
            ],
        ]);
        $segmentPath = storage_path('app/private/test-motion-editor-live-confirmed.mkv');
        File::ensureDirectoryExists(dirname($segmentPath));
        File::put($segmentPath, 'brief-motion-live');

        try {
            $motion = app(RecordingMotionDetectorService::class)->detectPreviewClip($camera, $segmentPath);
        } finally {
            File::delete($segmentPath);
        }

        $this->assertTrue($motion['detected']);
        $this->assertSame(8, $motion['changed_pixels']);
        $this->assertEqualsCanonicalizing([0, 1, 4, 5], $motion['changed_indexes']);
    }

    public function test_preview_activity_returns_to_quiet_while_the_segment_still_qualifies_for_recording(): void
    {
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        $binary = storage_path('app/private/test-binaries/motion-editor-latest.sh');
        File::ensureDirectoryExists(dirname($binary));
        $quiet = str_repeat(chr(0), 16);
        $moving = chr(255).chr(255).chr(0).chr(0).chr(255).chr(255).str_repeat(chr(0), 10);
        $frames = $quiet.$moving.$quiet.$quiet.$quiet.$quiet;
        File::put($binary, "#!/bin/sh\nprintf '%s' '".base64_encode($frames)."' | base64 -d\n");
        chmod($binary, 0755);
        config()->set('ffmpeg.ffmpeg.binaries', [$binary]);
        $camera = new Camera([
            'recording_motion_trigger_pixels' => 3,
            'recording_motion_mask' => ['version' => 1, 'grid_width' => 4, 'grid_height' => 4, 'runs' => [[0, 15]]],
        ]);

        try {
            $motion = app(RecordingMotionDetectorService::class)->detectPreviewClip($camera, '/unused-fixture.mkv');
        } finally {
            File::delete($binary);
        }

        $this->assertTrue($motion['detected']);
        $this->assertGreaterThan(0, $motion['changed_pixels']);
        $this->assertFalse($motion['latest']['detected']);
        $this->assertSame(0, $motion['latest']['effective_trigger_pixels']);
        $this->assertSame([], $motion['latest']['changed_indexes']);
    }

    public function test_live_preview_treats_an_incomplete_active_snapshot_as_waiting(): void
    {
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-preview-eof')]);

        $camera = Camera::query()->create([
            'name' => 'Incomplete live preview',
            'local_ip' => '192.168.1.92',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream21',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'recording_motion_trigger_pixels' => 3,
        ]);
        $segmentPath = storage_path('app/private/test-motion-editor-incomplete-live.mkv');
        File::ensureDirectoryExists(dirname($segmentPath));
        File::put($segmentPath, 'incomplete-live-snapshot');

        try {
            $motion = app(RecordingMotionDetectorService::class)->detectPreviewClip($camera, $segmentPath);
            $closedClipException = null;

            try {
                app(RecordingMotionDetectorService::class)->detectClip($camera, $segmentPath);
            } catch (\RuntimeException $exception) {
                $closedClipException = $exception;
            }
        } finally {
            File::delete($segmentPath);
        }

        $this->assertFalse($motion['detected']);
        $this->assertSame(0, $motion['frame_count']);
        $this->assertSame([], $motion['changed_indexes']);
        $this->assertInstanceOf(\RuntimeException::class, $closedClipException);
        $this->assertStringContainsString('End of file', $closedClipException->getMessage());
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

    public function test_it_caps_the_stored_activity_ratio_for_weighted_motion_clusters(): void
    {
        config()->set('queue.default', 'sync');
        config()->set('recording.motion.grid_width', 4);
        config()->set('recording.motion.grid_height', 4);
        config()->set('recording.motion.cluster_bonus_min_size', 3);
        config()->set('recording.motion.cluster_bonus_multiplier', 2);

        Camera::query()->create([
            'name' => 'Bounded Motion Score Yard',
            'local_ip' => '192.168.1.95',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream24',
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

        $this->assertSame('1.0000', CameraRecording::query()->firstOrFail()->motion_score);
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

        config()->set('ffmpeg.ffmpeg.binaries', [$this->fakeFfmpegBinary('motion-corner')]);

        // Supply closed segments explicitly: the background fake can otherwise
        // finish the entire motion event before the preferred row even exists.
        $startedAt = Carbon::create(2026, 4, 10, 15, 0, 0, 'UTC');
        $segments = [
            [
                'path' => storage_path('app/private/preferred-preroll.mkv'),
                'started_at' => $startedAt->copy()->subSeconds(2),
                'ended_at' => $startedAt->copy(),
            ],
            [
                'path' => storage_path('app/private/preferred-motion.mkv'),
                'started_at' => $startedAt->copy(),
                'ended_at' => $startedAt->copy()->addSecond(),
            ],
            [
                'path' => storage_path('app/private/preferred-quiet.mkv'),
                'started_at' => $startedAt->copy()->addSecond(),
                'ended_at' => $startedAt->copy()->addSeconds(5),
            ],
        ];
        File::put($segments[0]['path'], 'quiet');
        File::put($segments[1]['path'], 'motion');
        File::put($segments[2]['path'], 'quiet');

        $segmenter = \Mockery::mock(MotionRecordingSegmenterService::class);
        $segmenter->shouldReceive('syncCamera')->twice()
            ->andReturn(['started' => false, 'running' => true, 'pid' => 1234]);
        $segmenter->shouldReceive('closedSegmentsSince')->twice()
            ->andReturn([$segments[1]], [$segments[2]]);
        $segmenter->shouldReceive('segmentsForWindow')->twice()
            ->andReturn(array_slice($segments, 0, 2), $segments);
        $segmenter->shouldReceive('pruneSegments')->twice()->andReturn(0);
        $this->app->instance(MotionRecordingSegmenterService::class, $segmenter);

        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => now()->utc()->startOfMinute(),
            'message' => 'Retrying the same motion event.',
        ]);

        app(CameraRecordingService::class)->syncMotionRecorder($camera, $recording);

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_PROCESSING, $recording->status);
        $this->assertNotNull($recording->started_at);
        $this->assertNull($recording->relative_path);
        $this->assertDatabaseCount('camera_recordings', 1);
        $this->assertDatabaseHas('camera_motion_states', [
            'camera_id' => $camera->id,
            'active_recording_id' => $recording->id,
        ]);

        app(CameraRecordingService::class)->syncMotionRecorder($camera, $recording);

        $recording->refresh();

        $this->assertSame(CameraRecording::STATUS_RECORDED, $recording->status);
        $this->assertTrue($recording->ended_at->equalTo($segments[2]['ended_at']));
        $this->assertSame('capture-3', file_get_contents(storage_path('app/private/'.$recording->relative_path)));
        $this->assertDatabaseCount('camera_recordings', 1);
        $this->assertDatabaseHas('camera_motion_states', [
            'camera_id' => $camera->id,
            'active_recording_id' => null,
        ]);
        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, fn ($job) => $job->recordingId === $recording->id);
    }

    public function test_it_discards_a_preferred_motion_row_when_no_motion_event_claims_it(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Quiet Retry Bay',
            'local_ip' => '192.168.1.86',
            'rtsp_path' => '/stream15',
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
        ]);
        $recording = CameraRecording::query()->create([
            'camera_id' => $camera->id,
            'capture_mode' => Camera::RECORDING_MODE_MOTION,
            'status' => CameraRecording::STATUS_QUEUED,
            'scheduled_for' => now()->utc()->startOfMinute(),
        ]);

        $segmenter = \Mockery::mock(MotionRecordingSegmenterService::class);
        $segmenter->shouldReceive('syncCamera')->once()
            ->andReturn(['started' => false, 'running' => true, 'pid' => 1234]);
        $segmenter->shouldReceive('closedSegmentsSince')->once()->andReturn([]);
        $segmenter->shouldReceive('pruneSegments')->once()->andReturn(0);
        $this->app->instance(MotionRecordingSegmenterService::class, $segmenter);

        app(CameraRecordingService::class)->syncMotionRecorder($camera, $recording);

        $this->assertModelMissing($recording);
        $this->assertDatabaseCount('camera_recordings', 0);
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
        $now = Carbon::create(2026, 4, 10, 19, 5, 0, 'UTC');

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

            $scheduledFor = Carbon::create(2026, 4, 10, 15, 21, 53, 'UTC');
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

            CameraMotionState::query()->create([
                'camera_id' => $camera->id,
                'active_recording_id' => $recording->id,
                'event_started_at' => $eventStartedAt,
                'last_motion_at' => $scheduledFor->copy()->addSeconds(20),
                'finalize_after' => $finalizeAfter,
            ]);

            $storage = app(CameraStorageService::class);
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

            $storage = \Mockery::mock(CameraStorageService::class, [app(ApplicationSettingsService::class)])
                ->makePartial();
            $storage->shouldReceive('finalizeStagedWrite')
                ->once()
                ->with($relativePath, $absolutePath)
                ->andThrow(new \RuntimeException('Unable to verify the uploaded file on the active camera storage disk.'));
            $this->app->instance(CameraStorageService::class, $storage);

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
                    \Mockery::on(fn ($keepFrom): bool => $keepFrom instanceof Carbon
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
                \Mockery::type(Carbon::class),
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
                \Mockery::type(Carbon::class),
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

        $eventStartedAt = Carbon::create(2026, 4, 10, 15, 0, 0, 'UTC')->startOfSecond();
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

        CameraMotionState::query()->create([
            'camera_id' => $camera->id,
            'active_recording_id' => $activeRecording->id,
            'event_started_at' => $eventStartedAt,
            'last_motion_at' => $rolloverStartedAt->copy()->subSeconds(4),
            'finalize_after' => $rolloverStartedAt->copy()->addSeconds(20),
        ]);

        $storage = app(CameraStorageService::class);
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
            ->with(\Mockery::type(Camera::class), \Mockery::type(Carbon::class), \Mockery::type(Carbon::class), false)
            ->andReturn([$previousSegment], []);
        $segmenter->shouldReceive('pruneSegments')
            ->once()
            ->with(
                \Mockery::on(fn (Camera $resolvedCamera): bool => $resolvedCamera->is($camera)),
                \Mockery::on(fn ($keepFrom): bool => $keepFrom instanceof Carbon
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
        $motionState = CameraMotionState::query()->where('camera_id', $camera->id)->firstOrFail();

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

        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, function (GenerateRecordingReviewAssetsJob $job) use ($activeRecording): bool {
            return $job->recordingId === $activeRecording->id;
        });
    }
}
