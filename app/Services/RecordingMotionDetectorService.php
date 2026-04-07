<?php

namespace App\Services;

use App\Models\Camera;
use RuntimeException;
use Symfony\Component\Process\Process;

class RecordingMotionDetectorService
{
    public function __construct(
        private readonly RecordingMotionMaskService $maskService,
    ) {
    }

    /**
     * @param  array{authenticated_uri: string, transport: string}  $source
     * @return array{detected: bool, activity_ratio: float, changed_pixels: int, selected_pixels: int, frame_count: int}
     */
    public function detect(Camera $camera, array $source): array
    {
        $analysisSeconds = max(3, (int) config('recording.motion.analysis_seconds', 5));

        return $this->detectFromInput($camera, array_merge([
            '-rtsp_transport',
            $source['transport'],
            '-thread_queue_size',
            (string) config('ffmpeg.motion.thread_queue_size', 512),
            '-timeout',
            (string) config('ffmpeg.motion.rw_timeout', 20000000),
            '-rtbufsize',
            (string) config('ffmpeg.motion.rtbufsize', '32M'),
            '-fflags',
            (string) config('ffmpeg.motion.input_fflags', '+genpts+discardcorrupt'),
            '-use_wallclock_as_timestamps',
            config('ffmpeg.motion.use_wallclock_timestamps', true) ? '1' : '0',
            '-probesize',
            (string) config('ffmpeg.motion.input_probe_size', 131072),
            '-analyzeduration',
            (string) config('ffmpeg.motion.input_analyze_duration', 500000),
            '-t',
            (string) $analysisSeconds,
            '-i',
            $source['authenticated_uri'],
        ]), $analysisSeconds);
    }

    /**
     * @return array{detected: bool, activity_ratio: float, changed_pixels: int, selected_pixels: int, frame_count: int}
     */
    public function detectClip(Camera $camera, string $absolutePath, ?int $offsetSeconds = null, ?int $durationSeconds = null): array
    {
        $inputArguments = [];

        if ($offsetSeconds !== null && $offsetSeconds > 0) {
            $inputArguments[] = '-ss';
            $inputArguments[] = (string) $offsetSeconds;
        }

        if ($durationSeconds !== null && $durationSeconds > 0) {
            $inputArguments[] = '-t';
            $inputArguments[] = (string) $durationSeconds;
        }

        $inputArguments[] = '-i';
        $inputArguments[] = $absolutePath;

        return $this->detectFromInput($camera, $inputArguments, $durationSeconds);
    }

    /**
     * @param  array<int, string>  $inputArguments
     * @return array{detected: bool, activity_ratio: float, changed_pixels: int, selected_pixels: int, frame_count: int}
     */
    private function detectFromInput(Camera $camera, array $inputArguments, ?int $expectedDurationSeconds = null): array
    {
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));

        if ($ffmpegBinary === null) {
            throw new RuntimeException('ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        $mask = $camera->recordingMotionMask();
        $gridWidth = $mask['grid_width'];
        $gridHeight = $mask['grid_height'];
        $frameSize = $gridWidth * $gridHeight;
        $selectedIndexes = $this->maskService->selectedIndexes($mask);
        $selectedPixels = count($selectedIndexes);

        if ($selectedPixels < 1 || $frameSize < 1) {
            return [
                'detected' => false,
                'activity_ratio' => 0.0,
                'changed_pixels' => 0,
                'selected_pixels' => 0,
                'frame_count' => 0,
            ];
        }

        $analysisSeconds = max(3, (int) config('recording.motion.analysis_seconds', 5));
        $analysisFps = max(1, (int) config('recording.motion.analysis_fps', 3));
        $process = new Process(array_merge([
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
        ], $inputArguments, [
            '-an',
            '-sn',
            '-dn',
            '-vf',
            sprintf('fps=%d,scale=%d:%d,format=gray,showinfo', $analysisFps, $gridWidth, $gridHeight),
            '-f',
            'rawvideo',
            'pipe:1',
        ]));
        $process->setTimeout(max($analysisSeconds, (int) ($expectedDurationSeconds ?? 0)) + 15);
        $process->run();

        if (!$process->isSuccessful()) {
            $message = trim($process->getErrorOutput() ?: $process->getOutput());

            throw new RuntimeException($message !== ''
                ? 'Unable to evaluate motion for this camera. '.$message
                : 'Unable to evaluate motion for this camera.');
        }

        $output = $process->getOutput();
        $errorOutput = $process->getErrorOutput();
        $frameCount = $frameSize > 0 ? intdiv(strlen($output), $frameSize) : 0;

        if ($frameCount < 2) {
            $legacyHits = preg_match_all('/showinfo/', $errorOutput) ?: 0;

            if ($legacyHits > 0) {
                return [
                    'detected' => true,
                    'activity_ratio' => 1.0,
                    'changed_pixels' => $selectedPixels,
                    'selected_pixels' => $selectedPixels,
                    'frame_count' => max(2, $legacyHits + 1),
                ];
            }

            return [
                'detected' => false,
                'activity_ratio' => 0.0,
                'changed_pixels' => 0,
                'selected_pixels' => $selectedPixels,
                'frame_count' => $frameCount,
            ];
        }

        $pixelDeltaThreshold = $this->maskService->pixelDeltaThreshold();
        $activityThreshold = $camera->motionTriggerThreshold() / 100;
        $peakChangedPixels = 0;
        $detected = false;
        $previousFrame = substr($output, 0, $frameSize);

        for ($frameIndex = 1; $frameIndex < $frameCount; $frameIndex++) {
            $currentFrame = substr($output, $frameIndex * $frameSize, $frameSize);
            $changedPixels = 0;

            foreach ($selectedIndexes as $index) {
                if (abs(ord($currentFrame[$index]) - ord($previousFrame[$index])) >= $pixelDeltaThreshold) {
                    $changedPixels++;
                }
            }

            $peakChangedPixels = max($peakChangedPixels, $changedPixels);

            if (($changedPixels / $selectedPixels) >= $activityThreshold) {
                $detected = true;
            }

            $previousFrame = $currentFrame;
        }

        return [
            'detected' => $detected,
            'activity_ratio' => round($peakChangedPixels / $selectedPixels, 4),
            'changed_pixels' => $peakChangedPixels,
            'selected_pixels' => $selectedPixels,
            'frame_count' => $frameCount,
        ];
    }

    /**
     * @param  array<int, string>  $candidates
     */
    private function resolveBinary(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}