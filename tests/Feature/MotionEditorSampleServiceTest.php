<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Services\MotionEditorSampleService;
use App\Services\RecordingMotionDetectorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class MotionEditorSampleServiceTest extends TestCase
{
    private string $segment;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('recording.motion.editor_cache_store', 'array');
        $this->segment = storage_path('app/private/test-motion-sample.mkv');
        File::ensureDirectoryExists(dirname($this->segment));
        File::put($this->segment, 'video');
    }

    protected function tearDown(): void
    {
        File::delete($this->segment);
        parent::tearDown();
    }

    private function camera(): Camera
    {
        return (new Camera([
            'recording_motion_mask' => ['grid_width' => 4, 'grid_height' => 4, 'runs' => [[0, 15]]],
            'recording_motion_trigger_pixels' => 3,
        ]))->forceFill(['id' => 42]);
    }

    public function test_viewers_share_one_decode_and_a_cache_hit_does_not_renew_freshness(): void
    {
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('detectPreviewClip')->once()->andReturn(['frame_count' => 4]);
        });
        $samples = app(MotionEditorSampleService::class);
        $first = $samples->sample($this->camera(), $this->segment);
        $this->travel(4)->seconds();
        $second = $samples->sample($this->camera(), $this->segment);
        $this->assertSame($first, $second);
        $this->assertGreaterThanOrEqual(4, (now()->getTimestampMs() / 1000) - $second['observed_at']);
        $this->assertSame([], File::glob(storage_path('app/private/ffmpeg-temp/motion-editor/camera-42-*')));
    }

    public function test_a_growing_file_is_sampled_at_most_once_per_analysis_frame_for_identical_settings(): void
    {
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('detectPreviewClip')->once()->andReturn(['frame_count' => 4]);
        });
        $samples = app(MotionEditorSampleService::class);
        $first = $samples->sample($this->camera(), $this->segment);
        File::append($this->segment, 'more data');
        $this->assertSame($first, $samples->sample($this->camera(), $this->segment));
    }

    public function test_audio_growth_and_draft_changes_do_not_make_the_same_video_sample_fresh(): void
    {
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('detectPreviewClip')->times(3)->andReturn(['frame_count' => 4]);
        });
        $samples = app(MotionEditorSampleService::class);
        $camera = $this->camera();
        $first = $samples->sample($camera, $this->segment);
        $this->travel(2)->seconds();
        File::append($this->segment, 'audio packets');
        $second = $samples->sample($camera, $this->segment);
        $camera->recording_motion_trigger_pixels = 9;
        $third = $samples->sample($camera, $this->segment);
        $this->assertSame($first['observed_at'], $second['observed_at']);
        $this->assertSame($first['observed_at'], $third['observed_at']);
        $this->assertNotSame($first['settings'], $third['settings']);
    }

    public function test_new_decoded_frames_advance_the_sample_and_snapshot_is_bounded_to_observed_bytes(): void
    {
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('detectPreviewClip')->once()->withArgs(function (Camera $camera, string $snapshot): bool {
                File::append($this->segment, 'new packets');

                return $snapshot !== $this->segment && File::get($snapshot) === 'video';
            })->andReturn(['frame_count' => 4]);
            $mock->shouldReceive('detectPreviewClip')->once()->andReturn(['frame_count' => 6]);
        });
        $samples = app(MotionEditorSampleService::class);
        $first = $samples->sample($this->camera(), $this->segment);
        $this->travel(1)->seconds();
        $second = $samples->sample($this->camera(), $this->segment);
        $this->assertNotSame($first['sample_id'], $second['sample_id']);
        $this->assertGreaterThan($first['observed_at'], $second['observed_at']);
    }

    public function test_busy_analysis_does_not_start_another_decoder(): void
    {
        $this->mock(RecordingMotionDetectorService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('detectPreviewClip'));
        $lock = Cache::store('array')->lock('motion-editor:v2:42:lock', 15);
        $this->assertTrue($lock->get());
        try {
            $this->assertNull(app(MotionEditorSampleService::class)->sample($this->camera(), $this->segment));
        } finally {
            $lock->release();
        }
    }

    public function test_decoder_failure_releases_lock_and_removes_snapshot_for_a_successful_retry(): void
    {
        $this->mock(RecordingMotionDetectorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('detectPreviewClip')->once()->andThrow(new RuntimeException('decode failed'));
            $mock->shouldReceive('detectPreviewClip')->once()->andReturn(['frame_count' => 4]);
        });
        $samples = app(MotionEditorSampleService::class);
        try {
            $samples->sample($this->camera(), $this->segment);
            $this->fail('Expected a decode error.');
        } catch (RuntimeException $exception) {
            $this->assertSame('decode failed', $exception->getMessage());
        }
        $this->assertSame([], File::glob(storage_path('app/private/ffmpeg-temp/motion-editor/camera-42-*')));
        $this->assertSame(4, $samples->sample($this->camera(), $this->segment)['motion']['frame_count']);
    }
}
