<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\Onvif\OnvifPtzService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class LiveWallPtzController extends Controller
{
    public function show(Camera $camera, OnvifPtzService $ptz): JsonResponse
    {
        abort_unless($camera->is_enabled, 404);
        try {
            return response()->json($ptz->capabilities($camera))->header('Cache-Control', 'no-store');
        } catch (RuntimeException $exception) {
            return response()->json(['supported' => false, 'message' => $exception->getMessage()], 503)->header('Cache-Control', 'no-store');
        }
    }

    public function store(Request $request, Camera $camera, OnvifPtzService $ptz): JsonResponse
    {
        abort_unless($camera->is_enabled && $camera->supports_onvif, 404);
        $validated = $request->validate([
            'command' => ['required', Rule::in(['up', 'down', 'left', 'right', 'up-left', 'up-right', 'down-left', 'down-right', 'zoom-in', 'zoom-out', 'stop'])],
        ]);
        try {
            $ptz->move($camera, $validated['command']);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['message' => $validated['command'] === 'stop' ? 'Camera stopped.' : 'Movement sent.']);
    }
}
