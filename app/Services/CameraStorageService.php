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