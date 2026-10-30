<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\CameraShortcutIconService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CameraShortcutManifestController extends Controller
{
    public function __invoke(Camera $camera, CameraShortcutIconService $icons): JsonResponse
    {
        abort_unless($camera->is_enabled && $camera->supports_rtsp, Response::HTTP_NOT_FOUND);

        $livePath = route('cameras.live', ['camera' => $camera], false);

        return response()->json([
            'id' => $livePath,
            'name' => $camera->name,
            'short_name' => $camera->name,
            'start_url' => $livePath,
            'scope' => substr($livePath, 0, strrpos($livePath, '/') + 1),
            'display' => 'standalone',
            'background_color' => '#10141b',
            'theme_color' => '#10141b',
            'icons' => $icons->forCamera($camera)['icons'],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }
}
