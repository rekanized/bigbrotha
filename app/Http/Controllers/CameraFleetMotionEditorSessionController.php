<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\Relay\MediaMtxAccessTokenService;
use App\Services\Relay\MediaMtxConfigService;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CameraFleetMotionEditorSessionController extends Controller
{
    public function __invoke(
        Request $request,
        Camera $camera,
        MediaMtxConfigService $relayConfig,
        MediaMtxAccessTokenService $accessTokenService,
        MediaMtxProcessService $relayProcess,
    ): JsonResponse {
        abort_unless($camera->supports_rtsp, Response::HTTP_NOT_FOUND);

        $relayStatus = $relayProcess->ensureRunning();

        abort_unless(
            ($relayStatus['running'] ?? false) && ($relayStatus['api_reachable'] ?? false),
            Response::HTTP_SERVICE_UNAVAILABLE,
            'Media relay is not available.',
        );

        $profileIndex = $request->query('profileIndex');
        $selectedProfileIndex = is_numeric($profileIndex) ? (int) $profileIndex : null;
        $definition = $relayConfig->cameraRecordingRelayDefinition($camera, $selectedProfileIndex);

        abort_unless(is_array($definition), Response::HTTP_NOT_FOUND);

        $whepUrl = $relayConfig->browserWhepUrlForPath($definition['path'], $request);

        return response()->json([
            'camera' => [
                'id' => $camera->getKey(),
                'name' => $camera->name,
                'path' => $definition['path'],
            ],
            'profile_index' => $definition['index'],
            'whep_url' => $whepUrl,
            'reader_url' => $relayConfig->browserReaderUrlForPath($definition['path'], $request),
            'access_token' => $accessTokenService->issueReadToken($request->user(), $definition['path']),
            'expires_in' => $accessTokenService->ttl(),
        ]);
    }
}