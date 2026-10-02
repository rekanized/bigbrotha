<?php

namespace Tests\Feature;

use App\Jobs\GenerateRecordingReviewAssetsJob;
use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\CameraStorageService;
use App\Services\ContinuousRecordingSegmenterService;
use App\Services\RecordingReviewAssetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ContinuousRecordingPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_camera_gops_produce_minute_clips_and_playable_review_assets(): void
    {
        Queue::fake();
        $camera = Camera::query()->create(['name' => 'Continuous pipeline', 'local_ip' => '192.0.2.1']);
        $directory = app(CameraStorageService::class)->ensureCameraDirectories($camera).'/recordings';
        File::ensureDirectoryExists($directory);
        $input = $directory.'/source.mkv';

        // The input deliberately has no keyframe at either minute boundary.
        $this->runMediaCommand([
            'ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=96x64:rate=5',
            '-f', 'lavfi', '-i', 'sine=frequency=440:sample_rate=48000', '-t', '125',
            '-c:v', 'libx264', '-preset', 'ultrafast', '-g', '2000', '-sc_threshold', '0',
            '-c:a', 'pcm_s16le', $input,
        ]);

        $segmenter = app(ContinuousRecordingSegmenterService::class);
        $command = (new ReflectionMethod($segmenter, 'buildCommand'))->invoke($segmenter, $camera, [
            'index' => null, 'authenticated_uri' => 'rtsp://example.invalid/stream', 'transport' => 'tcp',
        ]);
        // Exercise the real output profile against a deterministic file instead of a camera network.
        $inputIndex = array_search('-i', $command, true);
        $command = array_merge(['ffmpeg', '-v', 'error', '-i', $input], array_slice($command, $inputIndex + 2));
        $command[array_search('-strftime', $command, true) + 1] = '0';
        $command[array_key_last($command)] = $directory.'/segment-%03d.mkv';
        $this->runMediaCommand($command);

        $files = File::glob($directory.'/segment-*.mkv');
        $this->assertCount(3, $files);
        $start = now()->utc()->startOfSecond()->subSeconds(125);

        foreach ($files as $index => $path) {
            $duration = $this->mediaDuration($path);
            $this->assertEqualsWithDelta($index < 2 ? 60 : 5, $duration, 0.15);
            rename($path, $directory.'/'.$start->copy()->addSeconds($index * 60)->format('Ymd_His').'-continuous.mkv');
        }

        $import = new ReflectionMethod($segmenter, 'importSegments');
        $this->assertSame(3, $import->invoke($segmenter, $camera, null, false));
        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, 3);
        $this->assertSame([60, 60, 5], CameraRecording::query()->orderBy('started_at')->get()
            ->map(fn ($recording) => (int) $recording->started_at->diffInSeconds($recording->ended_at))->all());

        foreach (CameraRecording::query()->get() as $recording) {
            $review = app(RecordingReviewAssetService::class);
            $manifest = $review->generateForRecording($recording);
            $this->assertSame('ready', $manifest['playback_status']);
            $this->assertSame('ready', $manifest['scrub_status']);
            $playback = $review->playbackAbsolutePath($recording->fresh());
            $this->assertEqualsWithDelta($manifest['duration_seconds'], $this->mediaDuration($playback), 0.2);
            $this->runMediaCommand(['ffmpeg', '-v', 'error', '-xerror', '-i', $playback, '-f', 'null', '-']);
        }
    }

    public function test_import_preserves_outage_gaps_and_does_not_republish_converted_clips(): void
    {
        Queue::fake();
        $camera = Camera::query()->create(['name' => 'Interrupted camera', 'local_ip' => '192.0.2.2']);
        $directory = app(CameraStorageService::class)->ensureCameraDirectories($camera).'/recordings';
        File::ensureDirectoryExists($directory);
        $first = $directory.'/20261002_120000-continuous.mkv';
        $next = $directory.'/20261002_120500-continuous.mkv';
        $this->runMediaCommand(['ffmpeg', '-v', 'error', '-f', 'lavfi', '-i', 'testsrc2=size=96x64:rate=5', '-t', '3', '-c:v', 'libx264', $first]);
        File::copy($first, $next);
        $segmenter = app(ContinuousRecordingSegmenterService::class);
        $import = new ReflectionMethod($segmenter, 'importSegments');
        $this->assertSame(2, $import->invoke($segmenter, $camera, null, false));
        $recording = CameraRecording::query()->orderBy('started_at')->firstOrFail();
        $this->assertSame('2026-10-02 12:00:03', $recording->ended_at->toDateTimeString());
        $convertedPath = str_replace('.mkv', '.mp4', $recording->relative_path);
        $recording->forceFill(['relative_path' => $convertedPath])->save();
        $this->assertSame(0, $import->invoke($segmenter, $camera, null, false));
        $this->assertSame($convertedPath, $recording->fresh()->relative_path);
        Queue::assertPushed(GenerateRecordingReviewAssetsJob::class, 2);
    }

    private function runMediaCommand(array $command): void
    {
        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    }

    private function mediaDuration(string $path): float
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $path]);
        $process->mustRun();

        return (float) trim($process->getOutput());
    }
}
