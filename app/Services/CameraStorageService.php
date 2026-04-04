<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\CameraRecording;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
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
            $cameraRoot.'/recordings',
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
        $relativePath = $this->privateStorageRelativePath($previewPath);

        if ($relativePath === null) {
            return null;
        }

        $absolutePath = storage_path('app/private/'.$relativePath);

        if (is_file($absolutePath)) {
            return $absolutePath;
        }

        return null;
    }

    public function recordingAbsolutePath(Camera $camera, \DateTimeInterface $timestamp, string $fileName): string
    {
        $cameraRoot = $this->ensureCameraDirectories($camera);
        $recordingDirectory = $cameraRoot.'/recordings/'.$timestamp->format('Y/m/d');

        $this->ensureWritableDirectory($recordingDirectory);

        return $recordingDirectory.'/'.$fileName;
    }

    public function normalizePrivateStorageRelativePath(?string $path): ?string
    {
        return $this->privateStorageRelativePath($path);
    }

    public function recordingRelativePathFromAbsolute(string $absolutePath): string
    {
        $prefix = storage_path('app/private/');

        if (!str_starts_with($absolutePath, $prefix)) {
            throw new RuntimeException('Recording path must live under private storage.');
        }

        return ltrim(substr($absolutePath, strlen($prefix)), '/');
    }

    public function recordingReviewAssetRelativePath(?string $recordingPath, string $fileName): ?string
    {
        $normalizedPath = $this->privateStorageRelativePath($recordingPath);

        if ($normalizedPath === null) {
            return null;
        }

        $directory = trim(dirname($normalizedPath), './');
        $baseName = pathinfo($normalizedPath, PATHINFO_FILENAME);

        if ($baseName === '') {
            return null;
        }

        $relativeDirectory = ($directory !== '' ? $directory.'/' : '').'_review/'.$baseName;

        return $relativeDirectory.'/'.ltrim($fileName, '/');
    }

    public function recordingReviewAssetAbsolutePath(?string $recordingPath, string $fileName, bool $ensureDirectory = false): ?string
    {
        $relativePath = $this->recordingReviewAssetRelativePath($recordingPath, $fileName);

        if ($relativePath === null) {
            return null;
        }

        $absolutePath = storage_path('app/private/'.$relativePath);

        if ($ensureDirectory) {
            $this->ensureWritableDirectory(dirname($absolutePath));
        }

        return $absolutePath;
    }

    public function resolveReviewAssetAbsolutePath(?string $assetPath): ?string
    {
        return $this->resolveRecordingAbsolutePath($assetPath);
    }

    public function deleteRecordingReviewAssets(?string $recordingPath): void
    {
        $directory = $this->recordingReviewAssetAbsolutePath($recordingPath, '.', false);

        if ($directory === null) {
            return;
        }

        File::deleteDirectory(dirname($directory));
    }

    public function deleteRecordingFile(?string $recordingPath): bool
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null) {
            return true;
        }

        $absolutePath = storage_path('app/private/'.$relativePath);

        if (!is_file($absolutePath)) {
            $this->pruneEmptyRecordingDirectories($absolutePath);

            return true;
        }

        $deleted = Storage::disk('local')->delete($relativePath);

        clearstatcache(true, $absolutePath);

        if (is_file($absolutePath)) {
            return false;
        }

        $this->pruneEmptyRecordingDirectories($absolutePath);

        return $deleted || !is_file($absolutePath);
    }

    public function resolveRecordingAbsolutePath(?string $recordingPath): ?string
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null) {
            return null;
        }

        $absolutePath = storage_path('app/private/'.$relativePath);

        if (is_file($absolutePath)) {
            return $absolutePath;
        }

        return null;
    }

    public function pruneEmptyRecordingDirectories(string $absolutePath): void
    {
        $directory = dirname($absolutePath);
        $cameraRecordingsRoot = dirname(dirname(dirname($absolutePath)));

        while (str_starts_with($directory, $cameraRecordingsRoot) && $directory !== $cameraRecordingsRoot) {
            $entries = @scandir($directory) ?: [];
            $entries = array_values(array_diff($entries, ['.', '..']));

            if ($entries !== []) {
                break;
            }

            @rmdir($directory);
            $directory = dirname($directory);
        }

        $reviewRoot = $this->recordingReviewAssetAbsolutePath($this->recordingRelativePathFromAbsolute($absolutePath), '.', false);

        if ($reviewRoot !== null) {
            $reviewParent = dirname($reviewRoot);

            if (is_dir($reviewParent)) {
                $entries = @scandir($reviewParent) ?: [];
                $entries = array_values(array_diff($entries, ['.', '..']));

                if ($entries === []) {
                    @rmdir($reviewParent);
                }
            }
        }
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    public function orphanRecordingFiles(): array
    {
        $cameraRoot = storage_path('app/private/cameras');

        if (!is_dir($cameraRoot)) {
            return [];
        }

        $trackedPaths = array_fill_keys(
            CameraRecording::query()
                ->whereNotNull('relative_path')
                ->pluck('relative_path')
                ->map(fn ($path): ?string => $this->normalizePrivateStorageRelativePath($path))
                ->filter(fn ($path): bool => is_string($path) && $path !== '')
                ->values()
                ->all(),
            true,
        );

        $orphans = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cameraRoot, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() === false) {
                continue;
            }

            $absolutePath = str_replace('\\', '/', $file->getPathname());

            if (!str_contains($absolutePath, '/recordings/') || str_contains($absolutePath, '/_review/')) {
                continue;
            }

            try {
                $relativePath = $this->recordingRelativePathFromAbsolute($absolutePath);
            } catch (RuntimeException) {
                continue;
            }

            if (isset($trackedPaths[$relativePath])) {
                continue;
            }

            $reviewAssetRoot = $this->recordingReviewAssetAbsolutePath($relativePath, '.', false);
            $reviewDirectory = $reviewAssetRoot !== null ? dirname($reviewAssetRoot) : null;

            $orphans[] = [
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
                'size_bytes' => $file->getSize(),
                'modified_at' => date(DATE_ATOM, $file->getMTime()),
                'review_directory' => $reviewDirectory,
                'review_assets_present' => $reviewDirectory !== null && is_dir($reviewDirectory) ? 1 : 0,
            ];
        }

        usort($orphans, fn ($left, $right): int => strcmp((string) ($left['modified_at'] ?? ''), (string) ($right['modified_at'] ?? '')));

        return $orphans;
    }

    /**
     * @param  array<int, array<string, int|string|null>>|null  $orphans
     * @return array<string, int>
     */
    public function purgeOrphanRecordingFiles(?array $orphans = null): array
    {
        $orphans ??= $this->orphanRecordingFiles();

        $filesPurged = 0;
        $reviewDirectoriesPurged = 0;
        $bytesFreed = 0;

        foreach ($orphans as $orphan) {
            $absolutePath = is_string($orphan['absolute_path'] ?? null) ? $orphan['absolute_path'] : null;
            $relativePath = $this->normalizePrivateStorageRelativePath(is_string($orphan['relative_path'] ?? null) ? $orphan['relative_path'] : null);
            $reviewDirectory = is_string($orphan['review_directory'] ?? null) ? $orphan['review_directory'] : null;
            $hadReviewDirectory = $reviewDirectory !== null && is_dir($reviewDirectory);

            if ($absolutePath === null || $relativePath === null || !is_file($absolutePath)) {
                continue;
            }

            clearstatcache(true, $absolutePath);
            $fileSize = filesize($absolutePath);

            if (@unlink($absolutePath)) {
                $filesPurged++;

                if (is_int($fileSize)) {
                    $bytesFreed += $fileSize;
                }
            }

            $this->deleteRecordingReviewAssets($relativePath);
            $this->pruneEmptyRecordingDirectories($absolutePath);

            if ($hadReviewDirectory && !is_dir($reviewDirectory)) {
                $reviewDirectoriesPurged++;
            }
        }

        return [
            'orphans_found' => count($orphans),
            'files_purged' => $filesPurged,
            'review_directories_purged' => $reviewDirectoriesPurged,
            'bytes_freed' => $bytesFreed,
        ];
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

    private function privateStorageRelativePath(?string $path): ?string
    {
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', trim($path));
        $trimmedPath = ltrim($normalizedPath, '/');
        $privateStorageRoot = rtrim(str_replace('\\', '/', storage_path('app/private')), '/');

        foreach ([$normalizedPath, $trimmedPath] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if ($candidate === $privateStorageRoot) {
                return null;
            }

            if (str_starts_with($candidate, $privateStorageRoot.'/')) {
                return ltrim(substr($candidate, strlen($privateStorageRoot)), '/');
            }

            foreach (['storage/app/private/', 'app/private/'] as $prefix) {
                if (str_starts_with($candidate, $prefix)) {
                    return ltrim(substr($candidate, strlen($prefix)), '/');
                }
            }
        }

        return $trimmedPath !== '' ? $trimmedPath : null;
    }
}