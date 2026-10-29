<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CameraShortcutManifestController extends Controller
{
    public function __invoke(Camera $camera): JsonResponse
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
            'icons' => [
                ['src' => asset('img/bigbrotha-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('img/bigbrotha-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('img/bigbrotha-icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], headers: ['Content-Type' => 'application/manifest+json']);
    }
}
