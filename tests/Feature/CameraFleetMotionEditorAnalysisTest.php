<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Services\MotionRecordingSegmenterService;
use App\Services\RecordingMotionDetectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use Tests\TestCase;

class CameraFleetMotionEditorAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_recorder_detector_and_current_buffer_snapshot_for_draft_settings(): void
    {
        $operator = User::factory()->create();
        $camera = Camera::query()->create([
            'name' => 'Backyard',
            'local_ip' => '192.168.1.80',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'recording_motion_trigger_pixels' => 10,
            'recording_motion_mask' => [
                'version' => 1,
                'grid_width' => 4,
                'grid_height' => 4,
                'selected_pixels' => 16,
                'runs' => [[0, 15]],
            ],
        ]);
        $draftMask = [
            'version' => 1,
            'grid_width' => 4,
            'grid_height' => 4,
            'selected_pixels' => 4,
            'runs' => [[0, 1], [4, 5]],
        ];
        $startedAt = now()->utc()->startOfSecond()->subSeconds(4);
        $segmentPath = storage_path('app/private/test-motion-editor-live-buffer.mkv');
        $snapshotPattern = storage_path('app/private/ffmpeg-temp/motion-editor/camera-'.$camera->id.'-*');
        File::ensureDirectoryExists(dirname($segmentPath));
        File::put($segmentPath, 'live-buffer-contents');
        File::delete(File::glob($snapshotPattern));

        $this->mock(MotionRecordingSegmenterService::class, function (MockInterface $mock) use ($camera, $startedAt, $segmentPath): void {
            $mock->shouldReceive('closedSegmentsSince')->once()->withArgs(
                fn (Camera $candidate): bool => $candidate->is($camera),
                null,
                false,
            )->andReturn([
                [
                    'path' => $segmentPath,
                    'started_at' => $startedAt,
                    'ended_at' => $startedAt->copy()->addSeconds(4),
                ],
            ]);
        });
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock) use ($camera, $draftMask, $segmentPath): void {
            $mock->shouldReceive('detectPreviewClip')->once()->withArgs(
                function (Camera $candidate) use ($camera, $draftMask): bool {
                    return $candidate->getKey() === $camera->getKey()
                        && $candidate->recordingMotionMask() === $draftMask
                        && $candidate->motionTriggerPixels(4) === 3;
                },
                function (string $candidate) use ($segmentPath): bool {
                    return $candidate !== $segmentPath
                        && is_file($candidate)
                        && file_get_contents($candidate) === 'live-buffer-contents';
                },
            )->andReturn([
                'detected' => true,
                'activity_ratio' => 1.0,
                'changed_pixels' => 7,
                'selected_pixels' => 4,
                'frame_count' => 12,
                'changed_indexes' => [0, 1, 4],
            ]);
        });

        try {
            $this->actingAs($operator)
                ->postJson(route('camera-fleet.motion-editor-analysis', ['camera' => $camera->id]), [
                    'mask' => $draftMask,
                    'trigger_pixels' => 3,
                ])
                ->assertOk()
                ->assertJsonPath('status', 'ready')
                ->assertJsonPath('settings_saved', false)
                ->assertJsonPath('recording_event_active', false)
                ->assertJsonPath('segment.started_at', $startedAt->toIso8601String())
                ->assertJsonPath('segment.live', true)
                ->assertJsonPath('decision.detected', true)
                ->assertJsonPath('decision.effective_trigger_pixels', 7)
                ->assertJsonPath('decision.pixels_needed', 3)
                ->assertJsonPath('decision.changed_indexes', [0, 1, 4]);
        } finally {
            File::delete($segmentPath);
        }

        $this->assertSame([], File::glob($snapshotPattern));
    }

    public function test_it_explains_that_unsaved_motion_mode_cannot_control_the_recorder_yet(): void
    {
        $operator = User::factory()->create();
        $camera = Camera::query()->create([
            'name' => 'Garage',
            'local_ip' => '192.168.1.81',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_OFF,
            'recording_retention_days' => 1,
            'recording_motion_trigger_pixels' => 4,
            'recording_motion_mask' => [
                'version' => 1,
                'grid_width' => 4,
                'grid_height' => 4,
                'selected_pixels' => 16,
                'runs' => [[0, 15]],
            ],
        ]);

        $this->mock(MotionRecordingSegmenterService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('closedSegmentsSince');
        });
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('detectPreviewClip');
        });

        $this->actingAs($operator)
            ->postJson(route('camera-fleet.motion-editor-analysis', ['camera' => $camera->id]), [
                'mask' => $camera->recordingMotionMask(),
                'trigger_pixels' => 4,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('settings_saved', false)
            ->assertJsonPath('decision.detected', false)
            ->assertJsonPath('message', 'Save this camera in motion mode before testing the recorder decision.');
    }

    public function test_it_waits_until_the_active_segment_contains_confirmed_frames(): void
    {
        $operator = User::factory()->create();
        $camera = Camera::query()->create([
            'name' => 'Side gate',
            'local_ip' => '192.168.1.82',
            'rtsp_port' => 554,
            'rtsp_path' => '/stream1',
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_retention_days' => 1,
            'recording_motion_trigger_pixels' => 4,
            'recording_motion_mask' => [
                'version' => 1,
                'grid_width' => 4,
                'grid_height' => 4,
                'selected_pixels' => 16,
                'runs' => [[0, 15]],
            ],
        ]);
        $segmentPath = storage_path('app/private/test-motion-editor-unconfirmed-buffer.mkv');
        File::ensureDirectoryExists(dirname($segmentPath));
        File::put($segmentPath, 'partial-live-buffer');

        $this->mock(MotionRecordingSegmenterService::class, function (MockInterface $mock) use ($segmentPath): void {
            $mock->shouldReceive('closedSegmentsSince')->once()->andReturn([
                [
                    'path' => $segmentPath,
                    'started_at' => now()->utc()->subSecond(),
                    'ended_at' => now()->utc()->addSeconds(4),
                ],
            ]);
        });
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('detectPreviewClip')->once()->andReturn([
                'detected' => false,
                'activity_ratio' => 0.0,
                'changed_pixels' => 0,
                'selected_pixels' => 16,
                'frame_count' => 2,
                'changed_indexes' => [],
            ]);
        });

        try {
            $this->actingAs($operator)
                ->postJson(route('camera-fleet.motion-editor-analysis', ['camera' => $camera->id]), [
                    'mask' => $camera->recordingMotionMask(),
                    'trigger_pixels' => 4,
                ])
                ->assertOk()
                ->assertJsonPath('status', 'waiting')
                ->assertJsonPath('decision.detected', false)
                ->assertJsonPath('message', 'Waiting for the live recorder segment to contain confirmed frames.');
        } finally {
            File::delete($segmentPath);
        }
    }
}
