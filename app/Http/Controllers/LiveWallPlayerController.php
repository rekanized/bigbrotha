<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\CameraLiveStreamService;
use App\Services\Relay\MediaMtxConfigService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LiveWallPlayerController extends Controller
{
    public function __invoke(
        Request $request,
        Camera $camera,
        CameraLiveStreamService $streamService,
        MediaMtxConfigService $relayConfig,
        MediaMtxProcessService $relayProcess,
    ): View {
        $this->assertCameraCanStream($camera);

        $relayStatus = $relayProcess->ensureRunning();
        $liveSelection = $streamService->selectWallProfile($camera);
        $liveDefinition = $relayConfig->cameraLivePlaybackDefinition($camera);

        return view('live-wall.player', [
            'camera' => $camera,
            'liveSelection' => $liveSelection,
            'relayStatus' => $relayStatus,
            'sessionUrl' => $liveDefinition !== null ? route('live-wall.session', ['camera' => $camera]) : null,
            'streamFormat' => $relayConfig->browserCompatibleStreamFormat(),
            'webrtcPath' => $liveDefinition['path'] ?? $relayConfig->cameraPathName($camera),
            'webrtcWhepUrl' => $liveDefinition !== null ? $relayConfig->browserWhepUrlForPath($liveDefinition['path'], $request) : null,
        ]);
    }

    private function assertCameraCanStream(Camera $camera): void
    {
        abort_unless($camera->is_enabled && $camera->supports_rtsp, Response::HTTP_NOT_FOUND);
    }
}