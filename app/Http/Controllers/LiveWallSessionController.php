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

        // The wall page and standalone player already perform the configuration
        // sync/startup path. Reconnects only need a cheap health check; running a
        // full config rebuild for every tile can cause a large wall to stampede
        // the relay when several feeds recover at once.
        $relayStatus = $relayProcess->status();

        abort_unless(
            ($relayStatus['running'] ?? false) && ($relayStatus['api_reachable'] ?? false),
            Response::HTTP_SERVICE_UNAVAILABLE,
            'Media relay is not available.',
        );

        $liveSelection = $streamService->selectWallProfile($camera);
        $definition = $relayConfig->cameraLivePlaybackDefinition($camera);
        $whepUrl = $definition !== null
            ? $relayConfig->browserWhepUrlForPath($definition['path'], $request)
            : null;

        abort_unless(is_array($liveSelection) && is_string($whepUrl) && $whepUrl !== '', Response::HTTP_NOT_FOUND);

        $path = $definition['path'];

        return response()->json([
            'camera' => [
                'id' => $camera->getKey(),
                'name' => $camera->name,
                'path' => $path,
            ],
            'whep_url' => $whepUrl,
            'reader_url' => $relayConfig->browserReaderUrlForPath($path, $request),
            'access_token' => $accessTokenService->issueReadToken($request->user(), $path),
            'expires_in' => $accessTokenService->ttl(),
            'stream' => $relayConfig->browserCompatibleStreamFormat(),
        ])->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    private function assertCameraCanStream(Camera $camera): void
    {
        abort_unless($camera->is_enabled && $camera->supports_rtsp, Response::HTTP_NOT_FOUND);
    }
}
