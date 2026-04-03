<?php

namespace App\Livewire\Recordings;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Reactive;
use Livewire\Component;

class TimelineStage extends Component
{
    /**
     * @var array<string, mixed>
     */
    #[Reactive]
    public array $tile = [];

    /**
     * @var array<string, mixed>|null
     */
    #[Reactive]
    public ?array $segment = null;

    #[Reactive]
    public string $focusLabel = '';

    #[Reactive]
    public string $reviewRangeLabel = '';

    #[Reactive]
    public int $focusAtMs = 0;

    #[Reactive]
    public bool $isMuted = true;

    public function render(): View
    {
        return view('livewire.recordings.timeline-stage');
    }
}