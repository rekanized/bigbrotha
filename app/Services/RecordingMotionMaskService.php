<?php

namespace App\Services;

class RecordingMotionMaskService
{
    public function defaultGridWidth(): int
    {
        return max(32, (int) config('recording.motion.grid_width', 160));
    }

    public function defaultGridHeight(): int
    {
        return max(18, (int) config('recording.motion.grid_height', 90));
    }

    public function pixelDeltaThreshold(): int
    {
        return max(1, min(255, (int) config('recording.motion.pixel_delta_threshold', 18)));
    }

    /**
     * @param  array<string, mixed>|null  $legacyArea
     * @return array{version: int, grid_width: int, grid_height: int, selected_pixels: int, runs: array<int, array{0: int, 1: int}>}
     */
    public function normalize(mixed $mask, ?array $legacyArea = null): array
    {
        $width = $this->normalizedDimension(is_array($mask) ? ($mask['grid_width'] ?? null) : null, $this->defaultGridWidth());
        $height = $this->normalizedDimension(is_array($mask) ? ($mask['grid_height'] ?? null) : null, $this->defaultGridHeight());

        if (!is_array($mask)) {
            return $legacyArea !== null
                ? $this->rectangleMask($legacyArea, $width, $height)
                : $this->fullFrameMask($width, $height);
        }

        $runs = $this->normalizeRuns($mask['runs'] ?? [], $width * $height);

        if ($runs === [] && $legacyArea !== null) {
            return $this->rectangleMask($legacyArea, $width, $height);
        }

        return $this->buildMask($width, $height, $runs);
    }

    /**
     * @return array{version: int, grid_width: int, grid_height: int, selected_pixels: int, runs: array<int, array{0: int, 1: int}>}
     */
    public function fullFrameMask(?int $gridWidth = null, ?int $gridHeight = null): array
    {
        $width = $this->normalizedDimension($gridWidth, $this->defaultGridWidth());
        $height = $this->normalizedDimension($gridHeight, $this->defaultGridHeight());
        $totalPixels = $width * $height;

        return $this->buildMask($width, $height, $totalPixels > 0 ? [[0, $totalPixels - 1]] : []);
    }

    /**
     * @param  array<string, mixed>  $area
     * @return array{version: int, grid_width: int, grid_height: int, selected_pixels: int, runs: array<int, array{0: int, 1: int}>}
     */
    public function rectangleMask(array $area, ?int $gridWidth = null, ?int $gridHeight = null): array
    {
        $width = $this->normalizedDimension($gridWidth, $this->defaultGridWidth());
        $height = $this->normalizedDimension($gridHeight, $this->defaultGridHeight());
        $totalPixels = $width * $height;

        if ($totalPixels < 1) {
            return $this->buildMask($width, $height, []);
        }

        $x = max(0, min(95, (int) ($area['x'] ?? 0)));
        $y = max(0, min(95, (int) ($area['y'] ?? 0)));
        $regionWidth = max(5, min(100 - $x, (int) ($area['width'] ?? 100)));
        $regionHeight = max(5, min(100 - $y, (int) ($area['height'] ?? 100)));

        $startX = max(0, min($width - 1, (int) floor(($x / 100) * $width)));
        $startY = max(0, min($height - 1, (int) floor(($y / 100) * $height)));
        $endX = max($startX, min($width - 1, (int) ceil((($x + $regionWidth) / 100) * $width) - 1));
        $endY = max($startY, min($height - 1, (int) ceil((($y + $regionHeight) / 100) * $height) - 1));

        $runs = [];

        for ($row = $startY; $row <= $endY; $row++) {
            $runs[] = [($row * $width) + $startX, ($row * $width) + $endX];
        }

        return $this->buildMask($width, $height, $this->normalizeRuns($runs, $totalPixels));
    }

    /**
     * @param  array<int, int>  $bits
     * @return array{version: int, grid_width: int, grid_height: int, selected_pixels: int, runs: array<int, array{0: int, 1: int}>}
     */
    public function maskFromBits(array $bits, int $gridWidth, int $gridHeight): array
    {
        $width = $this->normalizedDimension($gridWidth, $this->defaultGridWidth());
        $height = $this->normalizedDimension($gridHeight, $this->defaultGridHeight());
        $expectedPixels = $width * $height;
        $runs = [];
        $runStart = null;

        for ($index = 0; $index < $expectedPixels; $index++) {
            $isSelected = (int) ($bits[$index] ?? 0) === 1;

            if ($isSelected && $runStart === null) {
                $runStart = $index;

                continue;
            }

            if (!$isSelected && $runStart !== null) {
                $runs[] = [$runStart, $index - 1];
                $runStart = null;
            }
        }

        if ($runStart !== null) {
            $runs[] = [$runStart, $expectedPixels - 1];
        }

        return $this->buildMask($width, $height, $runs);
    }

    /**
     * @param  array<string, mixed>  $mask
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function bounds(array $mask): array
    {
        $normalized = $this->normalize($mask);
        $width = $normalized['grid_width'];
        $height = $normalized['grid_height'];
        $selected = $this->selectedIndexes($normalized);

        if ($selected === []) {
            return [
                'x' => 0,
                'y' => 0,
                'width' => 100,
                'height' => 100,
            ];
        }

        $minX = $width - 1;
        $minY = $height - 1;
        $maxX = 0;
        $maxY = 0;

        foreach ($selected as $index) {
            $x = $index % $width;
            $y = intdiv($index, $width);
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }

        return [
            'x' => max(0, min(95, (int) floor(($minX / $width) * 100))),
            'y' => max(0, min(95, (int) floor(($minY / $height) * 100))),
            'width' => max(5, min(100, (int) ceil((($maxX - $minX + 1) / $width) * 100))),
            'height' => max(5, min(100, (int) ceil((($maxY - $minY + 1) / $height) * 100))),
        ];
    }

    /**
     * @param  array<string, mixed>  $mask
     * @return array<int, int>
     */
    public function selectedIndexes(array $mask): array
    {
        $normalized = $this->normalize($mask);
        $selected = [];

        foreach ($normalized['runs'] as [$start, $end]) {
            for ($index = $start; $index <= $end; $index++) {
                $selected[] = $index;
            }
        }

        return $selected;
    }

    /**
     * @param  mixed  $value
     */
    private function normalizedDimension(mixed $value, int $defaultValue): int
    {
        return max(1, min(640, is_numeric($value) ? (int) $value : $defaultValue));
    }

    /**
     * @param  mixed  $runs
     * @return array<int, array{0: int, 1: int}>
     */
    private function normalizeRuns(mixed $runs, int $maxPixels): array
    {
        if (!is_array($runs) || $maxPixels < 1) {
            return [];
        }

        $normalized = [];

        foreach ($runs as $run) {
            if (!is_array($run) || count($run) < 2) {
                continue;
            }

            $start = is_numeric($run[0] ?? null) ? (int) $run[0] : null;
            $end = is_numeric($run[1] ?? null) ? (int) $run[1] : null;

            if ($start === null || $end === null) {
                continue;
            }

            $start = max(0, min($maxPixels - 1, $start));
            $end = max($start, min($maxPixels - 1, $end));
            $normalized[] = [$start, $end];
        }

        usort($normalized, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        $merged = [];

        foreach ($normalized as [$start, $end]) {
            if ($merged === []) {
                $merged[] = [$start, $end];

                continue;
            }

            $lastIndex = count($merged) - 1;
            [$lastStart, $lastEnd] = $merged[$lastIndex];

            if ($start <= ($lastEnd + 1)) {
                $merged[$lastIndex] = [$lastStart, max($lastEnd, $end)];

                continue;
            }

            $merged[] = [$start, $end];
        }

        return $merged;
    }

    /**
     * @param  array<int, array{0: int, 1: int}>  $runs
     * @return array{version: int, grid_width: int, grid_height: int, selected_pixels: int, runs: array<int, array{0: int, 1: int}>}
     */
    private function buildMask(int $gridWidth, int $gridHeight, array $runs): array
    {
        $selectedPixels = 0;

        foreach ($runs as [$start, $end]) {
            $selectedPixels += ($end - $start) + 1;
        }

        return [
            'version' => 1,
            'grid_width' => $gridWidth,
            'grid_height' => $gridHeight,
            'selected_pixels' => $selectedPixels,
            'runs' => $runs,
        ];
    }
}