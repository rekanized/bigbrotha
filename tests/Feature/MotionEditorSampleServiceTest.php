<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Services\MotionEditorSampleService;
use App\Services\MotionEditorStreamService;
use App\Services\RecordingMotionDetectorService;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

class MotionEditorSampleServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('recording.motion.editor_cache_store', 'array');
    }

    private function camera(): Camera
    {
        return (new Camera([
            'recording_motion_mask' => ['grid_width' => 4, 'grid_height' => 4, 'runs' => [[0, 15]]],
            'recording_motion_trigger_pixels' => 3,
        ]))->forceFill(['id' => 42]);
    }

    private function window(int $count = 7): array
    {
        $now = now()->getTimestampMs() / 1000;

        return [
            'generation' => 'test', 'sequence' => $count,
            'frames' => array_map(fn ($i) => str_repeat(chr($i), 16), range(0, $count - 1)),
            'receivedAt' => array_map(fn ($i) => $now - (($count - 1 - $i) / 6), range(0, $count - 1)),
        ];
    }

    private function stream(array $window): void
    {
        $this->mock(MotionEditorStreamService::class, function (MockInterface $mock) use ($window): void {
            $mock->shouldReceive('frames')->andReturn($window);
            $mock->shouldReceive('frameStride')->andReturn(2);
            $mock->shouldReceive('frameRate')->andReturn(6);
        });
    }

    public function test_live_phases_preserve_the_recorders_comparison_spacing(): void
    {
        $window = $this->window(8);
        $this->stream($window);
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock) use ($window): void {
            $mock->shouldReceive('analyzeFrames')->once()->withArgs(fn (Camera $camera, string $frames, bool $lookahead, bool $latestOnly) => $lookahead && $latestOnly && $frames === $window['frames'][1].$window['frames'][3].$window['frames'][5].$window['frames'][7]
            )->andReturn(['frame_count' => 4]);
            $mock->shouldNotReceive('detectPreviewClip');
        });
        $sample = app(MotionEditorSampleService::class)->sample($this->camera());
        $this->assertSame('test:6', $sample['sample_id']);
        $this->assertSame($window['receivedAt'][5], $sample['observed_at']);
        $this->assertSame(6, $sample['sample_fps']);
        $this->assertSame(3, config('recording.motion.analysis_fps'));
    }

    public function test_identical_samples_and_settings_reuse_analysis_without_refreshing_age(): void
    {
        $this->stream($this->window());
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('analyzeFrames')->once()->andReturn(['frame_count' => 4]);
        });
        $samples = app(MotionEditorSampleService::class);
        $first = $samples->sample($this->camera());
        $this->travel(1)->seconds();
        $this->assertSame($first, $samples->sample($this->camera()));
    }

    public function test_different_drafts_reuse_frames_but_recompute_the_detector_decision(): void
    {
        $this->stream($this->window());
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('analyzeFrames')->twice()->andReturn(['frame_count' => 4]);
        });
        $samples = app(MotionEditorSampleService::class);
        $camera = $this->camera();
        $first = $samples->sample($camera);
        $camera->recording_motion_trigger_pixels = 9;
        $second = $samples->sample($camera);
        $this->assertSame($first['observed_at'], $second['observed_at']);
        $this->assertNotSame($first['settings'], $second['settings']);
    }

    public function test_expired_and_unconfirmed_frames_never_reach_the_detector(): void
    {
        $window = $this->window();
        $window['receivedAt'] = array_fill(0, 7, (now()->getTimestampMs() / 1000) - 4);
        $this->stream($window);
        $this->mock(RecordingMotionDetectorService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('analyzeFrames'));
        $this->assertNull(app(MotionEditorSampleService::class)->sample($this->camera()));
        $this->stream($this->window(4));
        $this->assertNull(app(MotionEditorSampleService::class)->sample($this->camera()));
    }

    public function test_busy_analysis_does_not_duplicate_detector_work(): void
    {
        $this->stream($this->window());
        $this->mock(RecordingMotionDetectorService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('analyzeFrames'));
        $lock = Cache::store('array')->lock('motion-editor:v3:42:lock', 10);
        $this->assertTrue($lock->get());
        try {
            $this->assertNull(app(MotionEditorSampleService::class)->sample($this->camera()));
        } finally {
            $lock->release();
        }
    }
}
