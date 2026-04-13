<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\Onvif\RtspStreamDiagnosticsService;
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
        RtspStreamDiagnosticsService $diagnostics,
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
        $definition = $relayConfig->cameraMotionEditorRelayDefinition($camera, $selectedProfileIndex);

        if (! is_array($definition)) {
            $definition = $this->refreshMotionEditorRelayDefinition($camera, $selectedProfileIndex, $relayConfig, $diagnostics);
        }

        if (! is_array($definition)) {
            return response()->json([
                'message' => 'The selected recording stream is not available for live motion editing right now.',
            ], Response::HTTP_NOT_FOUND);
        }

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

    private function refreshMotionEditorRelayDefinition(
        Camera $camera,
        ?int $selectedProfileIndex,
        MediaMtxConfigService $relayConfig,
        RtspStreamDiagnosticsService $diagnostics,
    ): ?array {
        $recordingDefinition = $relayConfig->cameraRecordingRelayDefinition($camera, $selectedProfileIndex);

        if (! is_array($recordingDefinition) || ! is_numeric($recordingDefinition['index'] ?? null)) {
            return null;
        }

        $profileIndex = (int) $recordingDefinition['index'];
        $profiles = $camera->rtspProfiles();
        $profile = $profiles[$profileIndex] ?? null;

        if (! is_array($profile) || ! is_string($profile['uri'] ?? null) || trim((string) $profile['uri']) === '') {
            return null;
        }

        $profiles[$profileIndex] = $diagnostics->testAndPreview($camera, $profile, $profileIndex);
        $metadata = $camera->metadata ?? [];
        $metadata['rtsp_profiles'] = array_values($profiles);
        $camera->forceFill(['metadata' => $metadata])->save();
        $camera->refresh();

        return $relayConfig->cameraMotionEditorRelayDefinition($camera, $selectedProfileIndex);
    }
}