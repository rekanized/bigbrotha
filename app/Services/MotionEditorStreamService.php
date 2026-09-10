<?php

namespace App\Services;

use App\Models\Camera;
use App\Services\Concerns\ResolvesConfiguredBinaries;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class MotionEditorStreamService
{
    use ResolvesConfiguredBinaries;

    public function __construct(private readonly CameraRecordingService $recordings) {}

    public function frameStride(): int
    {
        return max(1, min(4, (int) config('recording.motion.editor_sample_multiplier', 2)));
    }

    public function frameRate(): int
    {
        return max(1, (int) config('recording.motion.analysis_fps', 3)) * $this->frameStride();
    }

    public function frameLimit(): int
    {
        return (max(2, (int) config('recording.motion.persistence_window_frames', 2)) + 3) * $this->frameStride() + 1;
    }

    public function signature(Camera $camera, array $source): string
    {
        $mask = $camera->recordingMotionMask();

        return hash('sha256', serialize([
            $source['authenticated_uri'], $source['transport'], $mask['grid_width'], $mask['grid_height'],
            $this->frameRate(), $this->frameLimit(),
        ]));
    }

    /** Renew the viewer lease and return the latest shared raw-frame window. */
    public function frames(Camera $camera): ?array
    {
        $source = $this->recordings->resolveBufferedRecordingSource($camera);

        if ($source === null) {
            return null;
        }

        $signature = $this->signature($camera, $source);
        $cache = $this->cache();
        $key = $this->key($camera);
        $cache->put($key.':lease', $signature, $this->idleSeconds());

        if (! $this->isRunning($camera) && $cache->add($key.':starting', true, 2)) {
            $this->launch($camera, $signature);
        }

        $sample = $cache->get($key.':frames');

        return is_array($sample) && ($sample['signature'] ?? null) === $signature ? $sample : null;
    }

    /** The CLI producer holds a kernel lock, released even if it crashes. */
    public function run(Camera $camera, string $signature): int
    {
        $handle = fopen($this->lockPath($camera), 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the motion sampler lock.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return 0;
        }

        $process = null;
        $stop = false;
        $signalHandlers = [];
        $asyncSignals = null;
        $cache = $this->cache();
        $key = $this->key($camera);

        try {
            $source = $this->recordings->resolveBufferedRecordingSource($camera);
            if ($source === null || $this->signature($camera, $source) !== $signature
                || $cache->get($key.':lease') !== $signature) {
                return 0;
            }

            $cache->forget($key.':frames');
            $process = $this->decoder($camera, $source);
            $process->start();
            $mask = $camera->recordingMotionMask();
            $frameSize = $mask['grid_width'] * $mask['grid_height'];
            $pending = '';
            $frames = [];
            $receivedAt = [];
            $sequence = 0;
            $generation = (string) Str::uuid();
            $lastFrameAt = microtime(true);
            $checkLeaseAt = 0.0;
            if (function_exists('pcntl_async_signals')) {
                $asyncSignals = pcntl_async_signals(true);
                $signalHandlers = [SIGTERM => pcntl_signal_get_handler(SIGTERM), SIGINT => pcntl_signal_get_handler(SIGINT)];
                pcntl_signal(SIGTERM, function () use (&$stop): void {
                    $stop = true;
                });
                pcntl_signal(SIGINT, function () use (&$stop): void {
                    $stop = true;
                });
            }

            while (! $stop && $process->isRunning()) {
                $now = microtime(true);
                if ($now >= $checkLeaseAt) {
                    if ($cache->get($key.':lease') !== $signature) {
                        break;
                    }
                    $checkLeaseAt = $now + 0.25;
                }
                if ($now - $lastFrameAt > 10) {
                    break;
                }

                $pending .= $process->getIncrementalOutput();
                $process->clearOutput();
                $process->clearErrorOutput();
                $advanced = false;
                while (strlen($pending) >= $frameSize) {
                    $frames[] = substr($pending, 0, $frameSize);
                    $pending = substr($pending, $frameSize);
                    $receivedAt[] = $now;
                    if (count($frames) > $this->frameLimit()) {
                        array_shift($frames);
                        array_shift($receivedAt);
                    }
                    $sequence++;
                    $advanced = true;
                }
                if ($advanced) {
                    $lastFrameAt = $now;
                    $cache->forget($key.':error');
                    $cache->put($key.':frames', compact('signature', 'generation', 'sequence', 'frames', 'receivedAt'), $this->idleSeconds());
                }
                usleep(10000);
            }

            return 0;
        } finally {
            $process?->stop(0.5);
            foreach ($signalHandlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
            if ($asyncSignals !== null) {
                pcntl_async_signals($asyncSignals);
            }
            $cache->forget($key.':frames');
            if (! $stop && $cache->get($key.':lease') === $signature) {
                $cache->put($key.':error', 'The recording source is not delivering motion frames. Reconnecting…', 10);
            }
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function decoder(Camera $camera, array $source): Process
    {
        $binary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
        if ($binary === null) {
            throw new RuntimeException('ffmpeg is unavailable for live motion analysis.');
        }
        $mask = $camera->recordingMotionMask();
        $process = new Process([
            $binary, '-nostdin', '-hide_banner', '-loglevel', 'error',
            '-threads', '1', '-filter_threads', '1', '-rtsp_transport', $source['transport'],
            '-timeout', '5000000', '-fflags', '+genpts+discardcorrupt',
            '-use_wallclock_as_timestamps', '1', '-probesize', '131072', '-analyzeduration', '500000',
            '-i', $source['authenticated_uri'], '-map', '0:v:0', '-an', '-sn', '-dn',
            '-vf', sprintf('fps=%d,scale=%d:%d,format=gray', $this->frameRate(), $mask['grid_width'], $mask['grid_height']),
            '-threads', '1', '-flush_packets', '1', '-f', 'rawvideo', 'pipe:1',
        ]);
        $process->setTimeout(null);

        return $process;
    }

    public function waitingMessage(Camera $camera): string
    {
        return $this->cache()->get($this->key($camera).':error')
            ?? 'Starting live motion analysis from the recording source…';
    }

    protected function launch(Camera $camera, string $signature): void
    {
        $command = [PHP_BINDIR.'/php', base_path('artisan'), 'camera-motion:sample', (string) $camera->getKey(), $signature];
        $process = new Process(['sh', '-c', 'nohup '.implode(' ', array_map('escapeshellarg', $command)).' </dev/null >/dev/null 2>&1 &']);
        $process->setTimeout(3);
        $process->mustRun();
    }

    public function key(Camera $camera): string
    {
        return 'motion-stream:v1:'.$camera->getKey();
    }

    protected function isRunning(Camera $camera): bool
    {
        $handle = fopen($this->lockPath($camera), 'c');
        if ($handle === false) {
            throw new RuntimeException('Unable to open the motion sampler lock.');
        }
        $available = flock($handle, LOCK_EX | LOCK_NB);
        if ($available) {
            flock($handle, LOCK_UN);
        }
        fclose($handle);

        return ! $available;
    }

    public function lockPath(Camera $camera): string
    {
        $directory = storage_path('app/private/ffmpeg-temp/motion-live');
        File::ensureDirectoryExists($directory);

        return $directory.'/camera-'.(int) $camera->getKey().'.lock';
    }

    private function idleSeconds(): int
    {
        return max(3, min(30, (int) config('recording.motion.editor_idle_seconds', 5)));
    }

    private function cache(): Repository
    {
        return Cache::store(config('recording.motion.editor_cache_store', 'file'));
    }
}
