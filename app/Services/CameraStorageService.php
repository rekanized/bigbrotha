<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Facades\File;
use RuntimeException;

class CameraStorageService
{
    public function ensureCameraDirectories(Camera $camera): string
    {
        $cameraId = $camera->getKey();

        if (!is_int($cameraId) && !is_string($cameraId)) {
            throw new RuntimeException('The camera must be saved before its storage directories can be prepared.');
        }

        $cameraRoot = storage_path('app/private/cameras/'.(string) $cameraId);

        foreach ([
            storage_path('app/private/cameras'),
            $cameraRoot,
            $cameraRoot.'/previews',
        ] as $path) {
            $this->ensureWritableDirectory($path);
        }

        return $cameraRoot;
    }

    public function previewRelativePath(Camera $camera, string $fileName): string
    {
        $cameraId = $camera->getKey();

        if (!is_int($cameraId) && !is_string($cameraId)) {
            throw new RuntimeException('The camera must be saved before a preview path can be generated.');
        }

        return 'cameras/'.(string) $cameraId.'/previews/'.$fileName;
    }

    public function resolvePreviewAbsolutePath(?string $previewPath): ?string
    {
        if (!is_string($previewPath) || trim($previewPath) === '') {
            return null;
        }

        $normalizedPath = ltrim(str_replace('\\', '/', $previewPath), '/');
        $candidates = [$normalizedPath];

        if (str_starts_with($normalizedPath, 'app/private/')) {
            $candidates[] = substr($normalizedPath, strlen('app/private/'));
        }

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            $absolutePath = str_starts_with($candidate, 'app/')
                ? storage_path($candidate)
                : storage_path('app/private/'.$candidate);

            if (is_file($absolutePath)) {
                return $absolutePath;
            }
        }

        return null;
    }

    public function detectPreviewMimeType(?string $previewPath): ?string
    {
        $absolutePath = $this->resolvePreviewAbsolutePath($previewPath);

        if ($absolutePath === null) {
            return null;
        }

        $imageInfo = @getimagesize($absolutePath);

        if (!is_array($imageInfo) || !is_string($imageInfo['mime'] ?? null)) {
            return null;
        }

        return $imageInfo['mime'];
    }

    public function hasUsablePreview(?string $previewPath): bool
    {
        return $this->detectPreviewMimeType($previewPath) !== null;
    }

    public function deleteCameraDirectories(Camera $camera): void
    {
        $cameraId = $camera->getKey();

        if (!is_int($cameraId) && !is_string($cameraId)) {
            return;
        }

        File::deleteDirectory(storage_path('app/private/cameras/'.(string) $cameraId));
    }

    private function ensureWritableDirectory(string $path): void
    {
        File::ensureDirectoryExists($path, 0775, true);

        if (is_dir($path)) {
            @chmod($path, 02775);
        }

        clearstatcache(true, $path);

        if (!is_dir($path) || !is_writable($path)) {
            throw new RuntimeException('The camera storage directory is not writable: '.$path);
        }
    }
}