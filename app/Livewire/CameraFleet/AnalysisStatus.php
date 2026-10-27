<?php

namespace App\Livewire\CameraFleet;

use App\Models\Camera;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AnalysisStatus extends Component
{
    #[Locked]
    public int $cameraId;

    public function render()
    {
        $camera = Camera::find($this->cameraId);

        return view('livewire.camera-fleet.analysis-status', ['status' => $camera?->analysisStatus() ?? ['state' => 'disabled']]);
    }
}
