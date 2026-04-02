<?php

namespace App\Jobs;

use App\Models\Camera;
use App\Services\Onvif\RtspStreamDiagnosticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RefreshCameraPreviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 45;

    public function __construct(public int $cameraId)
    {
    }

    public function handle(RtspStreamDiagnosticsService $diagnostics): void
    {
        $camera = Camera::query()->find($this->cameraId);

        if (!$camera instanceof Camera || !$camera->supports_rtsp) {
            return;
        }

        $target = $camera->rtspPreviewRefreshTarget();

        if ($target === null) {
            return;
        }

        $profiles = $camera->rtspProfiles();
        $profileIndex = $target['index'];
        $profile = $profiles[$profileIndex] ?? null;

        if (!is_array($profile)) {
            return;
        }

        $profiles[$profileIndex] = $diagnostics->testAndPreview($camera, $profile, $profileIndex);

        $metadata = is_array($camera->metadata) ? $camera->metadata : [];
        $metadata['rtsp_profiles'] = array_values($profiles);
        $metadata['preview_maintenance'] = [
            'last_attempted_at' => now()->utc()->toIso8601String(),
            'last_profile_index' => $profileIndex,
        ];

        $camera->metadata = $metadata;
        $camera->last_seen_at = now();
        $camera->save();
    }
}