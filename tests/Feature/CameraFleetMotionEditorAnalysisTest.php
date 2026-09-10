<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Models\User;
use App\Services\MotionEditorSampleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class CameraFleetMotionEditorAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function camera(): Camera
    {
        return Camera::query()->create([
            'name' => 'Live motion', 'local_ip' => '192.168.1.80', 'supports_rtsp' => true,
            'is_enabled' => true, 'recording_mode' => Camera::RECORDING_MODE_MOTION,
            'recording_motion_trigger_pixels' => 10,
            'recording_motion_mask' => ['grid_width' => 4, 'grid_height' => 4, 'runs' => [[0, 15]]],
        ]);
    }

    public function test_motion_endpoints_require_authentication_and_empty_masks_skip_sampling(): void
    {
        $camera = $this->camera();
        $mask = $camera->recordingMotionMask();
        $mask['runs'] = [];
        $this->mock(MotionEditorSampleService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sample'));
        $url = route('camera-fleet.motion-editor-analysis', $camera);
        $this->postJson($url, ['mask' => $mask, 'trigger_pixels' => 3])->assertUnauthorized();
        $this->getJson(route('camera-fleet.motion-editor-session', $camera))->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url, ['mask' => $mask, 'trigger_pixels' => 3])
            ->assertOk()->assertJsonPath('status', 'waiting')->assertJsonPath('decision.selected_pixels', 0);
    }

    public function test_it_rejects_different_grids_and_unsaved_motion_mode_without_sampling(): void
    {
        $camera = $this->camera();
        $this->mock(MotionEditorSampleService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('sample'));
        $this->actingAs(User::factory()->create())->postJson(route('camera-fleet.motion-editor-analysis', $camera), [
            'mask' => ['grid_width' => 5, 'grid_height' => 4, 'runs' => [[0, 19]]], 'trigger_pixels' => 3,
        ])->assertUnprocessable();
        $camera->update(['recording_mode' => Camera::RECORDING_MODE_OFF]);
        $this->postJson(route('camera-fleet.motion-editor-analysis', $camera), [
            'mask' => $camera->recordingMotionMask(), 'trigger_pixels' => 3,
        ])->assertOk()->assertJsonPath('settings_saved', false)->assertJsonPath('status', 'waiting');
    }

    public function test_live_sampling_uses_drafts_without_saving_and_separates_pixels_from_weighting(): void
    {
        $camera = $this->camera();
        $now = now()->getTimestampMs() / 1000;
        $this->mock(MotionEditorSampleService::class, function (MockInterface $mock) use ($camera, $now): void {
            $mock->shouldReceive('sample')->once()->withArgs(fn (Camera $draft) => $draft->getKey() === $camera->getKey() && $draft->motionTriggerPixels() === 3
            )->andReturn([
                'sample_id' => 'generation:30', 'observed_at' => $now - 0.4,
                'started_at' => $now - 2, 'ended_at' => $now, 'sample_fps' => 6,
                'motion' => [
                    'frame_count' => 6, 'detected' => true, 'activity_ratio' => 0.5, 'changed_pixels' => 8,
                    'changed_indexes' => [0, 1, 4, 5], 'selected_pixels' => 16,
                    'latest' => ['detected' => true, 'effective_trigger_pixels' => 5,
                        'changed_indexes' => [0, 1, 4], 'activity_ratio' => 0.3125, 'reason' => 'movement'],
                ],
            ]);
        });
        $this->actingAs(User::factory()->create())->postJson(route('camera-fleet.motion-editor-analysis', $camera), [
            'mask' => $camera->recordingMotionMask(), 'trigger_pixels' => 3,
        ])->assertOk()->assertJsonPath('status', 'ready')->assertJsonPath('settings_saved', false)
            ->assertJsonPath('segment.source', 'shared_recording_source')->assertJsonPath('segment.sample_fps', 6)
            ->assertJsonPath('activity.moving_pixels', 3)->assertJsonPath('activity.effective_trigger_pixels', 5)
            ->assertJsonPath('activity.mask_activity_ratio', 0.1875)->assertJsonPath('decision.effective_trigger_pixels', 8);
        $this->assertSame(10, $camera->fresh()->recording_motion_trigger_pixels);
    }

    public function test_sampler_startup_returns_waiting_without_a_false_zero_measurement(): void
    {
        $camera = $this->camera();
        $this->mock(MotionEditorSampleService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('sample')->once()->andReturnNull();
            $mock->shouldReceive('waitingMessage')->once()->andReturn('Starting live motion analysis…');
        });
        $this->actingAs(User::factory()->create())->postJson(route('camera-fleet.motion-editor-analysis', $camera), [
            'mask' => $camera->recordingMotionMask(), 'trigger_pixels' => 10,
        ])->assertOk()->assertJsonPath('status', 'waiting')->assertJsonPath('settings_saved', true);
    }
}
