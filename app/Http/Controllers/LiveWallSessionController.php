<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\CameraLiveStreamService;
use App\Services\Relay\MediaMtxAccessTokenService;
use App\Services\Relay\MediaMtxConfigService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LiveWallSessionController extends Controller
{
    public function __invoke(
        Request $request,
        Camera $camera,
        CameraLiveStreamService $streamService,
        MediaMtxConfigService $relayConfig,
        MediaMtxAccessTokenService $accessTokenService,
        MediaMtxProcessService $relayProcess,
    ): JsonResponse {
        $this->assertCameraCanStream($camera);

        $relayStatus = $relayProcess->ensureRunning();

        abort_unless(
            ($relayStatus['running'] ?? false) && ($relayStatus['api_reachable'] ?? false),
            Response::HTTP_SERVICE_UNAVAILABLE,
            'Media relay is not available.',
        );

        $liveSelection = $streamService->selectWallProfile($camera);
        $whepUrl = $relayConfig->browserWhepUrl($camera, $request);

        abort_unless(is_array($liveSelection) && is_string($whepUrl) && $whepUrl !== '', Response::HTTP_NOT_FOUND);

        $path = $relayConfig->cameraPathName($camera);

        return response()->json([
            'camera' => [
                'id' => $camera->getKey(),
                'name' => $camera->name,
                'path' => $path,
            ],
            'whep_url' => $whepUrl,
            'reader_url' => preg_replace('#/whep$#', '/reader.js', $whepUrl),
            'access_token' => $accessTokenService->issueReadToken($request->user(), $path),
            'expires_in' => $accessTokenService->ttl(),
        ]);
    }

    private function assertCameraCanStream(Camera $camera): void
    {
        abort_unless($camera->is_enabled && $camera->supports_rtsp, Response::HTTP_NOT_FOUND);
    }
}