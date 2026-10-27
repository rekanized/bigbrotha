<?php

namespace App\Services\Analysis;

use App\Models\Camera;
use RuntimeException;

/** Two outputs of one decoder, matched by sequence; no temporary image files. */
class AnalysisSampler
{
    private mixed $process = null;

    private float $lastReadAt;

    private array $pipes = [];

    private array $buffers = [1 => '', 3 => ''];

    private array $gray = [];

    private array $color = [];

    private int $graySequence = 0;

    private int $colorSequence = 0;

    public float $lastFrameAt;

    public function __construct(string $binary, Camera $camera, array $source, ?array $inputArguments = null)
    {
        $mask = $camera->recordingMotionMask();
        $this->graySize = $mask['grid_width'] * $mask['grid_height'];
        $filter = sprintf("fps=3,split=2[g][c];[g]scale=%d:%d,format=gray[gray];[c]scale=w='min(640,iw)':h='min(640,ih)':force_original_aspect_ratio=decrease,format=rgb24[color]", $mask['grid_width'], $mask['grid_height']);
        $command = [$binary, '-nostdin', '-hide_banner', '-loglevel', 'error', '-threads', '1', '-filter_complex_threads', '1',
            ...($inputArguments ?? ['-rtsp_transport', $source['transport'], '-timeout', '5000000', '-i', $source['authenticated_uri']]),
            '-filter_complex', $filter, '-map', '[gray]', '-an', '-threads', '1', '-f', 'rawvideo', 'pipe:3',
            '-map', '[color]', '-an', '-threads', '1', '-c:v', 'ppm', '-f', 'image2pipe', 'pipe:1'];
        $this->process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w'], 3 => ['pipe', 'w']], $this->pipes);
        if (! is_resource($this->process)) {
            throw new RuntimeException('Unable to start analysis decoder.');
        }
        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        $this->lastFrameAt = $this->lastReadAt = microtime(true);
    }

    private int $graySize;

    public function read(): array
    {
        if (! is_resource($this->process)) {
            throw new RuntimeException('Analysis decoder is closed.');
        }
        $now = microtime(true);
        if ($now - $this->lastReadAt > 2) {
            throw new RuntimeException('Analysis decoder frames became stale.');
        }
        $this->lastReadAt = $now;
        foreach ([1, 3] as $fd) {
            $this->buffers[$fd] .= stream_get_contents($this->pipes[$fd], 2 * 1024 * 1024);
            if (strlen($this->buffers[$fd]) > 8 * 1024 * 1024) {
                throw new RuntimeException('Analysis decoder exceeded its frame buffer.');
            }
        }
        while (strlen($this->buffers[3]) >= $this->graySize) {
            $this->gray[$this->graySequence++] = substr($this->buffers[3], 0, $this->graySize);
            $this->buffers[3] = substr($this->buffers[3], $this->graySize);
        }
        while (preg_match('/\AP6\s+(\d+)\s+(\d+)\s+255\s/', $this->buffers[1], $match)) {
            $size = (int) $match[1] * (int) $match[2] * 3 + strlen($match[0]);
            if ((int) $match[1] < 1 || (int) $match[1] > 640 || (int) $match[2] < 1 || (int) $match[2] > 640 || $size > 640 * 640 * 3 + 100) {
                throw new RuntimeException('Analysis decoder returned an oversized frame.');
            }
            if (strlen($this->buffers[1]) < $size) {
                break;
            }
            $this->color[$this->colorSequence++] = substr($this->buffers[1], 0, $size);
            $this->buffers[1] = substr($this->buffers[1], $size);
        }
        $frames = [];
        foreach ($this->gray as $sequence => $gray) {
            if (isset($this->color[$sequence])) {
                $frames[] = ['gray' => $gray, 'frame' => $this->color[$sequence], 'at' => microtime(true)];
                unset($this->gray[$sequence], $this->color[$sequence]);
                $this->lastFrameAt = microtime(true);
            }
        }
        if (count($this->gray) > 12 || count($this->color) > 12 || microtime(true) - $this->lastFrameAt > 10 || ($frames === [] && ! proc_get_status($this->process)['running'])) {
            throw new RuntimeException('Analysis stream unavailable or outputs out of sync.');
        }

        return $frames;
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            foreach ($this->pipes as $pipe) {
                fclose($pipe);
            }
            // Decoders must never hold up coordinator shutdown.
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
            $this->pipes = [];
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
