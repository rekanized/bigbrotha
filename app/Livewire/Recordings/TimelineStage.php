<?php

namespace App\Livewire\Recordings;

use Illuminate\Contracts\View\View;
use Livewire\Component;

class TimelineStage extends Component
{
    /**
     * @var array<string, mixed>
     */
    public array $tile = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $segment = null;

    public string $focusLabel = '';

    public string $reviewRangeLabel = '';

    public int $focusAtMs = 0;

    public function render(): View
    {
        return view('livewire.recordings.timeline-stage');
    }
}