<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\CameraLiveStreamService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiveWallStreamController extends Controller
{
    public function mjpeg(Request $request, Camera $camera, CameraLiveStreamService $streamService): StreamedResponse
    {
        $this->assertCameraCanStream($camera);

        return $streamService->mjpegResponse($camera, $this->requestedProfileIndex($request));
    }

    public function relay(Request $request, Camera $camera, CameraLiveStreamService $streamService): StreamedResponse
    {
        $this->assertCameraCanStream($camera);

        return $streamService->relayResponse($camera, $this->requestedProfileIndex($request));
    }

    private function assertCameraCanStream(Camera $camera): void
    {
        abort_unless($camera->is_enabled && $camera->supports_rtsp, Response::HTTP_NOT_FOUND);
    }

    private function requestedProfileIndex(Request $request): ?int
    {
        if (! $request->has('profileIndex')) {
            return null;
        }

        return max(0, $request->integer('profileIndex'));
    }
}
