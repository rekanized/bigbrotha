<?php

namespace App\Console\Commands;

use App\Models\Camera;
use App\Services\MotionEditorStreamService;
use Illuminate\Console\Command;

class SampleCameraMotion extends Command
{
    protected $signature = 'camera-motion:sample {camera} {signature}';

    protected $description = 'Sample the shared recording source while a motion editor is open';

    public function handle(MotionEditorStreamService $stream): int
    {
        $camera = Camera::find($this->argument('camera'));
        if (! $camera || ! $camera->is_enabled || ! $camera->supports_rtsp || $camera->recording_mode !== Camera::RECORDING_MODE_MOTION) {
            return self::SUCCESS;
        }

        return $stream->run($camera, (string) $this->argument('signature'));
    }
}
