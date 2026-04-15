<?php

namespace App\Services;

use App\Models\Camera;
use App\Services\Concerns\ResolvesConfiguredBinaries;
use RuntimeException;
use Symfony\Component\Process\Process;

class RecordingMotionDetectorService
{
    use ResolvesConfiguredBinaries;

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
        $triggerPixelThreshold = $camera->motionTriggerPixels($selectedPixels);
        $activityThreshold = min(1.0, $triggerPixelThreshold / $selectedPixels);
        $isolatedPixelRadius = max(1, (int) config('recording.motion.isolated_pixel_radius', 1));
        $minimumClusterPixels = max(3, (int) config('recording.motion.minimum_cluster_pixels', 3));
        $clusterBonusMinSize = max(3, (int) config('recording.motion.cluster_bonus_min_size', 3));
        $clusterBonusMultiplier = max(0, (int) config('recording.motion.cluster_bonus_multiplier', 2));
        $refreshSpikeWindowFrames = max(1, (int) config('recording.motion.persistence_window_frames', 2));
        $refreshSpikeActivityRatio = max(
            $activityThreshold,
            min(1.0, (float) config('recording.motion.refresh_spike_activity_ratio', 0.85))
        );
        $peakChangedPixels = 0;
        $detected = false;
        $frames = [];

        for ($frameIndex = 0; $frameIndex < $frameCount; $frameIndex++) {
            $frames[] = substr($output, $frameIndex * $frameSize, $frameSize);
        }

        for ($frameIndex = 1; $frameIndex < $frameCount; $frameIndex++) {
            $changedIndexes = $this->filterIsolatedChangedIndexes(
                $this->changedIndexes(
                $frames[$frameIndex - 1],
                $frames[$frameIndex],
                $selectedIndexes,
                $pixelDeltaThreshold,
                ),
                $gridWidth,
                $gridHeight,
                $isolatedPixelRadius,
                $minimumClusterPixels,
            );
            $changedPixels = $this->weightedTriggerPixels(
                $changedIndexes,
                $gridWidth,
                $gridHeight,
                $clusterBonusMinSize,
                $clusterBonusMultiplier,
            );
            $activityRatio = min(1.0, $changedPixels / $selectedPixels);

            if ($changedPixels < $triggerPixelThreshold) {
                continue;
            }

            if ($this->isIsolatedRefreshSpike(
                $frames,
                $frameIndex,
                $selectedIndexes,
                $frameSize,
                $pixelDeltaThreshold,
                $activityThreshold,
                $refreshSpikeActivityRatio,
                $refreshSpikeWindowFrames,
                $selectedPixels,
            )) {
                continue;
            }

            $peakChangedPixels = max($peakChangedPixels, $changedPixels);
            if ($activityRatio >= $activityThreshold) {
                $detected = true;
            }
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
     * @param  array<int, string>  $frames
     * @param  array<int, int>  $selectedIndexes
     */
    private function isIsolatedRefreshSpike(
        array $frames,
        int $frameIndex,
        array $selectedIndexes,
        int $frameSize,
        int $pixelDeltaThreshold,
        float $activityThreshold,
        float $refreshSpikeActivityRatio,
        int $refreshSpikeWindowFrames,
        int $selectedPixels,
    ): bool {
        $previousFrame = $frames[$frameIndex - 1] ?? null;
        $currentFrame = $frames[$frameIndex] ?? null;

        if ($previousFrame === null || $currentFrame === null) {
            return false;
        }

        $currentTransitionRatio = $this->changedPixelsAcrossFrame($previousFrame, $currentFrame, $frameSize, $pixelDeltaThreshold) / $frameSize;

        if ($currentTransitionRatio < $refreshSpikeActivityRatio) {
            return false;
        }

        for ($lookahead = 1; $lookahead <= $refreshSpikeWindowFrames; $lookahead++) {
            $futureFrame = $frames[$frameIndex + $lookahead] ?? null;

            if ($futureFrame === null) {
                break;
            }

            $futureChangedPixels = $this->changedPixelsAcrossFrame($currentFrame, $futureFrame, $frameSize, $pixelDeltaThreshold);
            $recoveredPixels = $this->changedPixelsAcrossFrame($previousFrame, $futureFrame, $frameSize, $pixelDeltaThreshold);

            if (($futureChangedPixels / $frameSize) >= $refreshSpikeActivityRatio
                && ($recoveredPixels / $frameSize) < $activityThreshold) {
                return true;
            }
        }

        for ($lookback = 1; $lookback <= $refreshSpikeWindowFrames; $lookback++) {
            $olderFrame = $frames[$frameIndex - $lookback - 1] ?? null;

            if ($olderFrame === null) {
                break;
            }

            $pastChangedPixels = $this->changedPixelsAcrossFrame($olderFrame, $previousFrame, $frameSize, $pixelDeltaThreshold);
            $recoveredPixels = $this->changedPixelsAcrossFrame($olderFrame, $currentFrame, $frameSize, $pixelDeltaThreshold);

            if (($pastChangedPixels / $frameSize) >= $refreshSpikeActivityRatio
                && ($recoveredPixels / $frameSize) < $activityThreshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, int>  $selectedIndexes
     */
    private function changedIndexes(string $leftFrame, string $rightFrame, array $selectedIndexes, int $pixelDeltaThreshold): array
    {
        $changedIndexes = [];

        foreach ($selectedIndexes as $index) {
            if (abs(ord($leftFrame[$index]) - ord($rightFrame[$index])) >= $pixelDeltaThreshold) {
                $changedIndexes[] = $index;
            }
        }

        return $changedIndexes;
    }

    /**
     * @param  array<int, int>  $changedIndexes
     * @return array<int, int>
     */
    private function filterIsolatedChangedIndexes(array $changedIndexes, int $gridWidth, int $gridHeight, int $radius, int $minimumClusterPixels): array
    {
        if (count($changedIndexes) < $minimumClusterPixels) {
            return [];
        }

        $changedLookup = array_fill_keys($changedIndexes, true);
        $filtered = [];
        $visited = [];

        foreach ($changedIndexes as $index) {
            if (isset($visited[$index])) {
                continue;
            }

            $cluster = $this->connectedClusterIndexes($index, $changedLookup, $visited, $gridWidth, $gridHeight, $radius);

            if (count($cluster) >= $minimumClusterPixels) {
                array_push($filtered, ...$cluster);
            }
        }

        return $filtered;
    }

    /**
     * @param  array<int, bool>  $changedLookup
     * @param  array<int, bool>  $visited
     * @return array<int, int>
     */
    private function connectedClusterIndexes(int $startingIndex, array $changedLookup, array &$visited, int $gridWidth, int $gridHeight, int $radius): array
    {
        $stack = [$startingIndex];
        $visited[$startingIndex] = true;
        $cluster = [];

        while ($stack !== []) {
            $index = array_pop($stack);

            if ($index === null) {
                continue;
            }

            $cluster[] = $index;
            $x = $index % $gridWidth;
            $y = intdiv($index, $gridWidth);

            for ($neighborY = max(0, $y - $radius); $neighborY <= min($gridHeight - 1, $y + $radius); $neighborY++) {
                for ($neighborX = max(0, $x - $radius); $neighborX <= min($gridWidth - 1, $x + $radius); $neighborX++) {
                    if ($neighborX === $x && $neighborY === $y) {
                        continue;
                    }

                    $neighborIndex = ($neighborY * $gridWidth) + $neighborX;

                    if (!isset($changedLookup[$neighborIndex]) || isset($visited[$neighborIndex])) {
                        continue;
                    }

                    $visited[$neighborIndex] = true;
                    $stack[] = $neighborIndex;
                }
            }
        }

        return $cluster;
    }

    private function changedPixelsAcrossFrame(string $leftFrame, string $rightFrame, int $frameSize, int $pixelDeltaThreshold): int
    {
        $changedPixels = 0;

        for ($index = 0; $index < $frameSize; $index++) {
            if (abs(ord($leftFrame[$index]) - ord($rightFrame[$index])) >= $pixelDeltaThreshold) {
                $changedPixels++;
            }
        }

        return $changedPixels;
    }

    /**
     * @param  array<int, int>  $changedIndexes
     */
    private function weightedTriggerPixels(array $changedIndexes, int $gridWidth, int $gridHeight, int $clusterBonusMinSize, int $clusterBonusMultiplier): int
    {
        if ($changedIndexes === []) {
            return 0;
        }

        $changedLookup = array_fill_keys($changedIndexes, true);
        $visited = [];
        $weightedPixels = 0;

        foreach ($changedIndexes as $index) {
            if (isset($visited[$index])) {
                continue;
            }

            $clusterSize = $this->connectedClusterSize($index, $changedLookup, $visited, $gridWidth, $gridHeight);

            $weightedPixels += $clusterSize;

            if ($clusterSize >= $clusterBonusMinSize) {
                $weightedPixels += ($clusterSize - ($clusterBonusMinSize - 1)) * $clusterBonusMultiplier;
            }
        }

        return $weightedPixels;
    }

    /**
     * @param  array<int, bool>  $changedLookup
     * @param  array<int, bool>  $visited
     */
    private function connectedClusterSize(int $startingIndex, array $changedLookup, array &$visited, int $gridWidth, int $gridHeight): int
    {
        $stack = [$startingIndex];
        $visited[$startingIndex] = true;
        $clusterSize = 0;

        while ($stack !== []) {
            $index = array_pop($stack);

            if ($index === null) {
                continue;
            }

            $clusterSize++;
            $x = $index % $gridWidth;
            $y = intdiv($index, $gridWidth);

            for ($neighborY = max(0, $y - 1); $neighborY <= min($gridHeight - 1, $y + 1); $neighborY++) {
                for ($neighborX = max(0, $x - 1); $neighborX <= min($gridWidth - 1, $x + 1); $neighborX++) {
                    if ($neighborX === $x && $neighborY === $y) {
                        continue;
                    }

                    $neighborIndex = ($neighborY * $gridWidth) + $neighborX;

                    if (!isset($changedLookup[$neighborIndex]) || isset($visited[$neighborIndex])) {
                        continue;
                    }

                    $visited[$neighborIndex] = true;
                    $stack[] = $neighborIndex;
                }
            }
        }

        return $clusterSize;
    }

}