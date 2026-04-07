<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Services\CameraStorageService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CameraFleetStreamPreviewController extends Controller
{
    public function __invoke(Camera $camera, int $profileIndex): Response|BinaryFileResponse
    {
        $profiles = $camera->rtspProfiles();
        $profile = $profiles[$profileIndex] ?? null;
        $previewPath = is_array($profile) ? ($profile['preview_path'] ?? null) : null;

        if (!is_string($previewPath) || $previewPath === '') {
            return $this->placeholderResponse();
        }

        $storage = app(CameraStorageService::class);
        $absolutePath = $storage->resolvePreviewAbsolutePath($previewPath);
        $imageMimeType = $storage->detectPreviewMimeType($previewPath);

        if ($absolutePath === null || $imageMimeType === null) {
            return $this->placeholderResponse();
        }

        $response = response()->file($absolutePath, [
            'Content-Type' => $imageMimeType,
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);

        $response->deleteFileAfterSend($storage->isTemporaryManagedPath($absolutePath));

        return $response;
    }

    private function placeholderResponse(): Response
    {
        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" width="640" height="360" viewBox="0 0 640 360" role="img" aria-label="Preview unavailable">
  <rect width="640" height="360" fill="#102033"/>
  <rect x="24" y="24" width="592" height="312" rx="18" fill="#16314b" stroke="#6fb3d2" stroke-width="2" stroke-dasharray="10 8"/>
  <circle cx="320" cy="146" r="34" fill="#6fb3d2" opacity="0.22"/>
  <path d="M302 130h36v32h-36z" fill="#6fb3d2" opacity="0.85"/>
  <path d="M338 138l20-12v40l-20-12z" fill="#f3c86a"/>
  <text x="320" y="228" text-anchor="middle" font-family="Arial, sans-serif" font-size="24" font-weight="700" fill="#eef5f9">Preview unavailable</text>
  <text x="320" y="258" text-anchor="middle" font-family="Arial, sans-serif" font-size="15" fill="#9cb8c8">Run a fresh stream test to capture a current thumbnail.</text>
</svg>
SVG;

        return response($svg, Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }
}