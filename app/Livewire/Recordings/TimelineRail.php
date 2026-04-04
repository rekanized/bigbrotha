<?php

namespace App\Livewire\Recordings;

use App\Services\ApplicationSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Livewire\Component;

class TimelineRail extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $tile = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $initialSegments = [];

    public int $initialWindowStartMs = 0;

    public int $initialWindowEndMs = 1000;

    public int $focusAtMs = 0;

    public string $focusLabel = '';

    public int $dayStartMs = 0;

    public int $dayEndMs = 1000;

    public float $timelineZoomScale = 1.0;

    public ?int $activeSegmentId = null;

    public function render(): View
    {
        return view('livewire.recordings.timeline-rail', [
            'timelineTicks' => $this->timelineTicks(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timelineTicks(): array
    {
        $reviewWindowStart = Carbon::createFromTimestampUTC((int) floor($this->dayStartMs / 1000));
        $reviewWindowEnd = Carbon::createFromTimestampUTC((int) floor($this->dayEndMs / 1000));
        $settings = app(ApplicationSettingsService::class);
        $ticks = [];
        $cursor = $reviewWindowStart->copy();
        $totalHours = max(1.0, $this->timelineDurationHours($reviewWindowStart, $reviewWindowEnd, $settings));
        $tickIntervalMinutes = 15;

        while ($cursor->lessThan($reviewWindowEnd)) {
            $nextCursor = $cursor->copy()->addMinutes($tickIntervalMinutes);

            if ($nextCursor->greaterThan($reviewWindowEnd)) {
                $nextCursor = $reviewWindowEnd->copy();
            }

            $offsetHours = $this->timelineHourOffset($reviewWindowStart, $cursor, $settings);
            $heightHours = max(0, $this->timelineHourOffset($reviewWindowStart, $nextCursor, $settings) - $offsetHours);
            $localizedCursor = $settings->toDisplayTimezone($cursor);
            $isPrimary = $localizedCursor->minute === 0;
            $isDayStart = $isPrimary && $localizedCursor->format('H:i') === '00:00';
            $labelVariant = $isDayStart ? 'day' : ($isPrimary ? 'hour' : 'minute');

            $ticks[] = [
                'focusMs' => $cursor->valueOf(),
                'topPercent' => round(($offsetHours / $totalHours) * 100, 6),
                'heightPercent' => round(($heightHours / $totalHours) * 100, 6),
                'isDayStart' => $isDayStart,
                'kind' => $isPrimary ? 'primary' : 'secondary',
                'labelVariant' => $labelVariant,
                'labelPrimary' => $isDayStart
                    ? $localizedCursor->format('M')
                    : ($isPrimary ? $localizedCursor->format('H') : null),
                'labelSecondary' => $localizedCursor->format($isDayStart ? 'd' : 'i'),
                'label' => $isDayStart
                    ? $localizedCursor->format('M d')
                    : ($isPrimary ? $localizedCursor->format('H:i') : $localizedCursor->format('i')),
                'ariaLabel' => $localizedCursor->format('Y-m-d H:i'),
            ];

            $cursor->addMinutes($tickIntervalMinutes);
        }

        return $ticks;
    }

    private function timelineDurationHours(Carbon $reviewWindowStart, Carbon $reviewWindowEnd, ApplicationSettingsService $settings): float
    {
        return max(1.0, $this->timelineHourOffset($reviewWindowStart, $reviewWindowEnd, $settings));
    }

    private function timelineHourOffset(Carbon $reviewWindowStart, Carbon $moment, ApplicationSettingsService $settings): float
    {
        $displayWindowStart = $settings->toDisplayTimezone($reviewWindowStart)->copy()->startOfHour();
        $displayMoment = $settings->toDisplayTimezone($moment);

        if ($displayMoment->lessThanOrEqualTo($displayWindowStart)) {
            return 0.0;
        }

        $displayHourStart = $displayMoment->copy()->startOfHour();
        $elapsedWholeHours = max(0, $displayWindowStart->diffInHours($displayHourStart, false));
        $secondsWithinHour = ($displayMoment->minute * 60) + $displayMoment->second;

        return $elapsedWholeHours + ($secondsWithinHour / 3600);
    }
}