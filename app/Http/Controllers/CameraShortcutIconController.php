<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\CameraShortcutIconService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CameraShortcutIconController extends Controller
{
    public function __invoke(Camera $camera, string $revision, int $size, CameraShortcutIconService $icons): BinaryFileResponse
    {
        abort_unless($camera->is_enabled && $camera->supports_rtsp, 404);

        return response()->file($icons->iconPath($camera, $revision, $size), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
