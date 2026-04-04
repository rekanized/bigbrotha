<?php

namespace App\Livewire\Recordings;

use App\Services\ApplicationSettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

class TimelineReview extends Component
{
    /**
     * @var array<string, int>
     */
    public array $summary = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $timelineCameraOptions = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $reviewTiles = [];

    public int $timelineHours = 24;

    public float $timelineZoomScale = 1.0;

    public float $timelineZoomMinScale = 1.0;

    public float $timelineZoomMaxScale = 8.0;

    public float $timelineZoomStepFactor = 1.18;

    public int $timelineBaseHourHeightPx = 88;

    public int $timelineMinTrackHeightPx = 1800;

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $timelineTicks = [];

    public int $dayStartMs = 0;

    public int $dayEndMs = 1000;

    public int $focusAtMs = 0;

    public string $reviewRangeLabel = '';

    public ?int $activeCameraId = null;

    public bool $isMuted = true;

    /**
     * @param  array<string, int>  $summary
     * @param  array<int, array<string, mixed>>  $timelineCameraOptions
     * @param  array<int, array<string, mixed>>  $reviewTiles
     * @param  array<int, array<string, mixed>>  $timelineTicks
     * @param  array<string, mixed>  $timelinePayload
     */
    public function mount(
        array $summary = [],
        array $timelineCameraOptions = [],
        array $reviewTiles = [],
        int $timelineHours = 24,
        array $timelineTicks = [],
        array $timelinePayload = [],
        string $reviewRangeLabel = '',
    ): void {
        $this->summary = $summary;
        $this->timelineCameraOptions = array_values($timelineCameraOptions);
        $this->reviewTiles = array_values($reviewTiles);
        $this->timelineHours = max(24, $timelineHours);
        $this->timelineZoomMinScale = 1.0;
        $this->timelineZoomMaxScale = (float) max(8, min(48, (int) ceil($this->timelineHours / 6)));
        $this->timelineZoomStepFactor = 1.18;
        $this->timelineBaseHourHeightPx = 88;
        $this->timelineMinTrackHeightPx = 1800;
        $this->timelineZoomScale = $this->clampZoomScale((float) ($timelinePayload['zoomScale'] ?? 1.0));
        $this->timelineTicks = array_values($timelineTicks);
        $this->dayStartMs = $this->asInt($timelinePayload['dayStartMs'] ?? 0);
        $this->dayEndMs = max($this->dayStartMs + 1000, $this->asInt($timelinePayload['dayEndMs'] ?? ($this->dayStartMs + 1000)));
        $this->focusAtMs = $this->clampFocusMs($this->asInt($timelinePayload['focusAtMs'] ?? $this->dayStartMs));
        $this->reviewRangeLabel = $reviewRangeLabel;
        $this->activeCameraId = $this->resolveActiveCameraId($timelinePayload['activeCameraId'] ?? null);
    }

    #[On('timeline-focus-selected')]
    public function selectFocus(int $focusMs, ?float $zoomScale = null): void
    {
        if ($zoomScale !== null) {
            $this->timelineZoomScale = $this->clampZoomScale($zoomScale);
        }

        $this->focusAtMs = $this->clampFocusMs($focusMs);
    }

    public function syncZoomScale(float $zoomScale): void
    {
        $this->timelineZoomScale = $this->clampZoomScale($zoomScale);
    }

    public function selectCamera(int $cameraId): void
    {
        $cameraId = $this->resolveActiveCameraId($cameraId);

        if ($cameraId !== null) {
            $this->activeCameraId = $cameraId;
        }
    }

    #[On('timeline-audio-toggled')]
    public function toggleAudio(): void
    {
        $this->isMuted = ! $this->isMuted;
    }

    public function render(): View
    {
        $currentTile = $this->currentTile();
        $currentSegment = $this->currentSegment($currentTile);

        return view('livewire.recordings.timeline-review', [
            'currentTile' => $currentTile,
            'currentSegment' => $currentSegment,
            'focusLabel' => $this->formatFocusLabel($this->focusAtMs),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentTile(): ?array
    {
        foreach ($this->reviewTiles as $tile) {
            if ((int) ($tile['cameraId'] ?? 0) === (int) ($this->activeCameraId ?? 0)) {
                return $tile;
            }
        }

        return $this->reviewTiles[0] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $tile
     * @return array<string, mixed>|null
     */
    private function currentSegment(?array $tile): ?array
    {
        if (!is_array($tile)) {
            return null;
        }

        $segments = $tile['segments'] ?? null;

        if (!is_array($segments) || $segments === []) {
            return null;
        }

        $exactMatch = null;

        foreach ($segments as $segment) {
            if (!is_array($segment)) {
                continue;
            }

            if ($this->segmentContainsFocus($segment, $this->focusAtMs)) {
                $exactMatch = $segment;

                break;
            }
        }

        if (is_array($exactMatch)) {
            return $exactMatch;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $segment
     */
    private function segmentContainsFocus(array $segment, int $focusMs): bool
    {
        $segmentStartMs = $this->asInt($segment['startMs'] ?? 0);
        $segmentEndMs = max($segmentStartMs, $this->asInt($segment['endMs'] ?? $segmentStartMs));

        if ($segmentEndMs <= $segmentStartMs) {
            return $focusMs === $segmentStartMs;
        }

        return $segmentStartMs <= $focusMs
            && $focusMs < $segmentEndMs;
    }

    private function clampFocusMs(int $focusMs): int
    {
        return min($this->timelineMaximumFocusMs(), max($this->dayStartMs, $focusMs));
    }

    private function timelineDurationMs(): int
    {
        return max(1, $this->dayEndMs - $this->dayStartMs);
    }

    private function timelineMaximumFocusMs(): int
    {
        return max($this->dayStartMs, $this->dayEndMs - 1000);
    }

    private function resolveActiveCameraId(mixed $cameraId): ?int
    {
        $cameraId = $this->asInt($cameraId);

        foreach ($this->reviewTiles as $tile) {
            if ((int) ($tile['cameraId'] ?? 0) === $cameraId) {
                return $cameraId;
            }
        }

        return isset($this->reviewTiles[0]['cameraId']) ? (int) $this->reviewTiles[0]['cameraId'] : null;
    }

    private function formatFocusLabel(int $focusMs): string
    {
        return app(ApplicationSettingsService::class)->formatDateTime(
            now()->setTimestamp((int) floor($focusMs / 1000))->utc(),
            'Y-m-d H:i:s'
        ) ?? gmdate('Y-m-d H:i:s', (int) floor($focusMs / 1000));
    }

    private function clampZoomScale(float $zoomScale): float
    {
        return max($this->timelineZoomMinScale, min($this->timelineZoomMaxScale, $zoomScale));
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}