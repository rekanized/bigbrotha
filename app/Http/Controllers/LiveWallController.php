<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\CameraLiveStreamService;
use App\Services\Relay\MediaMtxConfigService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LiveWallController extends Controller
{
    public function __invoke(
        Request $request,
        CameraLiveStreamService $streamService,
        MediaMtxConfigService $relayConfig,
        MediaMtxProcessService $relayProcess,
    ): View
    {
        $relayStatus = $relayProcess->ensureRunning();

        $tiles = Camera::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get()
            ->map(function (Camera $camera) use ($request, $relayConfig, $streamService): array {
                return [
                    'camera' => $camera,
                    'liveSelection' => $streamService->selectWallProfile($camera),
                    'webrtcPlayerUrl' => $relayConfig->browserPlayerUrl($camera, $request),
                    'webrtcPath' => $relayConfig->cameraPathName($camera),
                ];
            });

        return view('live-wall.index', [
            'tiles' => $tiles,
            'relayStatus' => $relayStatus,
        ]);
    }
}