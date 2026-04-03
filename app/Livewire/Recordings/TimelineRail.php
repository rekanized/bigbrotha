<?php

namespace App\Livewire\Recordings;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class TimelineRail extends Component
{
    /**
     * @var array<string, mixed>
     */
    #[Reactive]
    public array $tile = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    #[Reactive]
    public array $timelineTicks = [];

    #[Reactive]
    public int $focusAtMs = 0;

    #[Reactive]
    public string $focusLabel = '';

    #[Reactive]
    public string $reviewRangeLabel = '';

    #[Reactive]
    public int $dayStartMs = 0;

    #[Reactive]
    public int $dayEndMs = 1000;

    #[Reactive]
    public ?int $activeSegmentId = null;

    public function render(): View
    {
        return view('livewire.recordings.timeline-rail');
    }
}