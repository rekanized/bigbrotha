<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Services\CameraRecordingService;
use App\Services\MotionEditorStreamService;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MotionEditorStreamServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('recording.motion.editor_cache_store', 'array');
        config()->set('recording.motion.editor_idle_seconds', 3);
    }

    private function camera(): Camera
    {
        return (new Camera([
            'recording_motion_mask' => ['grid_width' => 4, 'grid_height' => 4, 'runs' => [[0, 15]]],
        ]))->forceFill(['id' => 42]);
    }

    public function test_viewers_share_startup_and_a_saved_source_change_invalidates_frames(): void
    {
        $recordings = Mockery::mock(CameraRecordingService::class);
        $source = ['authenticated_uri' => 'rtsp://reader:secret@relay:8554/camera-42-source', 'transport' => 'tcp'];
        $recordings->shouldReceive('resolveBufferedRecordingSource')->andReturn($source);
        $stream = new class($recordings) extends MotionEditorStreamService
        {
            public int $starts = 0;

            protected function launch(Camera $camera, string $signature): void
            {
                $this->starts++;
            }
        };
        $camera = $this->camera();
        $this->assertNull($stream->frames($camera));
        $this->assertNull($stream->frames($camera));
        $this->assertSame(1, $stream->starts);
        $cache = Cache::store('array');
        $this->assertSame($stream->signature($camera, $source), $cache->get($stream->key($camera).':lease'));
        $cache->put($stream->key($camera).':frames', ['signature' => 'old-source'], 10);
        $this->assertNull($stream->frames($camera));
    }

    public function test_no_source_means_no_direct_camera_fallback_or_process_launch(): void
    {
        $recordings = Mockery::mock(CameraRecordingService::class);
        $recordings->shouldReceive('resolveBufferedRecordingSource')->once()->andReturnNull();
        $stream = new class($recordings) extends MotionEditorStreamService
        {
            protected function launch(Camera $camera, string $signature): void
            {
                throw new \RuntimeException('must not launch');
            }
        };
        $this->assertNull($stream->frames($this->camera()));
    }

    public function test_a_kernel_lock_prevents_duplicate_producers(): void
    {
        $recordings = Mockery::mock(CameraRecordingService::class);
        $recordings->shouldNotReceive('resolveBufferedRecordingSource');
        $stream = new MotionEditorStreamService($recordings);
        $camera = $this->camera();
        $handle = fopen($stream->lockPath($camera), 'c');
        flock($handle, LOCK_EX);
        try {
            $this->assertSame(0, $stream->run($camera, 'duplicate'));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function test_producer_bounds_frame_history_and_stops_the_decoder_after_the_viewer_lease_expires(): void
    {
        $recordings = Mockery::mock(CameraRecordingService::class);
        $source = ['authenticated_uri' => 'rtsp://relay:8554/camera-42-source', 'transport' => 'tcp'];
        $recordings->shouldReceive('resolveBufferedRecordingSource')->andReturn($source);
        $stream = new class($recordings) extends MotionEditorStreamService
        {
            public ?Process $process = null;

            public function decoder(Camera $camera, array $source): Process
            {
                return $this->process = new Process([
                    '/usr/bin/ffmpeg', '-nostdin', '-v', 'error', '-re', '-f', 'lavfi',
                    '-i', 'testsrc2=size=4x4:rate=6', '-vf', 'format=gray', '-threads', '1',
                    '-flush_packets', '1', '-f', 'rawvideo', 'pipe:1',
                ]);
            }
        };
        $camera = $this->camera();
        $signature = $stream->signature($camera, $source);
        Cache::store('array')->put($stream->key($camera).':lease', $signature, 3);
        $windows = [];
        Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$windows): void {
            if (str_ends_with($event->key, ':frames')) {
                $windows[] = $event->value;
            }
        });
        $started = microtime(true);
        $this->assertSame(0, $stream->run($camera, $signature));
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertFalse($stream->process->isRunning());
        $this->assertGreaterThan(5, count($windows));
        $last = end($windows);
        $this->assertCount($stream->frameLimit(), $last['frames']);
        $this->assertSame(count($last['frames']), count($last['receivedAt']));
        $this->assertGreaterThan($stream->frameLimit(), $last['sequence']);
        $this->assertNull(Cache::store('array')->get($stream->key($camera).':frames'));
        $handle = fopen($stream->lockPath($camera), 'c');
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        fclose($handle);
    }

    public function test_decoder_uses_six_samples_per_second_with_bounded_threads_and_immediate_packet_flushing(): void
    {
        config()->set('ffmpeg.ffmpeg.binaries', ['/usr/bin/ffmpeg']);
        $stream = new MotionEditorStreamService(Mockery::mock(CameraRecordingService::class));
        $command = $stream->decoder($this->camera(), ['authenticated_uri' => 'rtsp://relay:8554/camera-42-source', 'transport' => 'tcp'])->getCommandLine();
        $this->assertStringContainsString('fps=6,scale=4:4,format=gray', $command);
        $this->assertStringContainsString("'-flush_packets' '1'", $command);
        $this->assertStringContainsString("'-threads' '1'", $command);
        $this->assertStringContainsString('rtsp://relay:8554/camera-42-source', $command);
        $this->assertSame(3, config('recording.motion.analysis_fps'));
    }
}
