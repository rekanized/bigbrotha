<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\CameraRecording;
use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class CameraStorageService
{
    public function __construct(
        private readonly ApplicationSettingsService $settings,
    ) {
    }

    public function usingNetworkStorage(): bool
    {
        return $this->settings->networkStorageEnabled();
    }

    public function ensureCameraDirectories(Camera $camera): string
    {
        $cameraId = $camera->getKey();

        if (!is_int($cameraId) && !is_string($cameraId)) {
            throw new RuntimeException('The camera must be saved before its storage directories can be prepared.');
        }

        $cameraDirectory = (string) $cameraId;

        if ($this->usingNetworkStorage()) {
            foreach ([$cameraDirectory, $cameraDirectory.'/previews', $cameraDirectory.'/recordings'] as $directory) {
                $this->cameraDisk()->makeDirectory($directory);
            }

            $stagingRoot = $this->writeStagingAbsolutePath('cameras/'.$cameraDirectory);

            foreach ([$stagingRoot, $stagingRoot.'/previews', $stagingRoot.'/recordings'] as $path) {
                $this->ensureWritableDirectory($path);
            }

            return $stagingRoot;
        }

        $cameraRoot = $this->privateAbsolutePath('cameras/'.$cameraDirectory);

        foreach ([$cameraRoot, $cameraRoot.'/previews', $cameraRoot.'/recordings'] as $path) {
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
        return $this->localReadablePath($previewPath);
    }

    public function recordingAbsolutePath(Camera $camera, \DateTimeInterface $timestamp, string $fileName): string
    {
        $cameraId = $camera->getKey();

        if (!is_int($cameraId) && !is_string($cameraId)) {
            throw new RuntimeException('The camera must be saved before a recording path can be generated.');
        }

        return $this->localWritablePath('cameras/'.(string) $cameraId.'/recordings/'.$timestamp->format('Y/m/d').'/'.$fileName);
    }

    public function normalizePrivateStorageRelativePath(?string $path): ?string
    {
        return $this->privateStorageRelativePath($path);
    }

    public function recordingRelativePathFromAbsolute(string $absolutePath): string
    {
        $relativePath = $this->privateStorageRelativePath($absolutePath);

        if ($relativePath === null) {
            throw new RuntimeException('Recording path must live under private storage or the camera staging workspace.');
        }

        return $relativePath;
    }

    public function recordingReviewAssetRelativePath(?string $recordingPath, string $fileName): ?string
    {
        $normalizedPath = $this->privateStorageRelativePath($recordingPath);

        if ($normalizedPath === null) {
            return null;
        }

        $reviewDirectory = $this->reviewAssetDirectoryRelativePath($normalizedPath);

        if ($reviewDirectory === null) {
            return null;
        }

        return $reviewDirectory.'/'.ltrim($fileName, '/');
    }

    public function recordingReviewAssetAbsolutePath(?string $recordingPath, string $fileName, bool $ensureDirectory = false): ?string
    {
        $relativePath = $this->recordingReviewAssetRelativePath($recordingPath, $fileName);

        if ($relativePath === null) {
            return null;
        }

        return $ensureDirectory
            ? $this->localWritablePath($relativePath)
            : $this->localReadablePath($relativePath);
    }

    public function resolveReviewAssetAbsolutePath(?string $assetPath): ?string
    {
        return $this->localReadablePath($assetPath);
    }

    public function deleteRecordingReviewAssets(?string $recordingPath): void
    {
        $normalizedPath = $this->privateStorageRelativePath($recordingPath);
        $reviewDirectory = $normalizedPath !== null ? $this->reviewAssetDirectoryRelativePath($normalizedPath) : null;

        if ($reviewDirectory === null) {
            return;
        }

        $this->deletePrivateDirectory($reviewDirectory);
    }

    public function deleteRecordingFile(?string $recordingPath): bool
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null) {
            return true;
        }

        if ($this->isCameraRelativePath($relativePath)) {
            if (!$this->usingNetworkStorage()) {
                $absolutePath = $this->privateAbsolutePath($relativePath);

                if (!is_file($absolutePath)) {
                    $this->pruneEmptyRecordingDirectories($relativePath);

                    return true;
                }

                $deleted = @unlink($absolutePath);
                clearstatcache(true, $absolutePath);
                $this->pruneEmptyRecordingDirectories($relativePath);

                return $deleted || !is_file($absolutePath);
            }

            $diskPath = $this->cameraDiskRelativePath($relativePath);

            if (!$this->cameraDisk()->exists($diskPath)) {
                $this->pruneEmptyRecordingDirectories($relativePath);

                return true;
            }

            $deleted = $this->cameraDisk()->delete($diskPath);
            $stillExists = $this->cameraDisk()->exists($diskPath);

            $this->pruneEmptyRecordingDirectories($relativePath);

            return $deleted || !$stillExists;
        }

        $absolutePath = $this->privateAbsolutePath($relativePath);

        if (!is_file($absolutePath)) {
            return true;
        }

        $deleted = Storage::disk('local')->delete($relativePath);

        clearstatcache(true, $absolutePath);

        return $deleted || !is_file($absolutePath);
    }

    public function resolveRecordingAbsolutePath(?string $recordingPath): ?string
    {
        return $this->localReadablePath($recordingPath);
    }

    public function recordingExists(?string $recordingPath): bool
    {
        return $this->privateFileExists($recordingPath);
    }

    /**
     * @return array{extension: string|null, mime_type: string|null, size: int|null}|null
     */
    public function recordingStreamMetadata(?string $recordingPath): ?array
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null) {
            return null;
        }

        $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));

        if (!$this->isCameraRelativePath($relativePath) || !$this->usingNetworkStorage()) {
            $absolutePath = $this->resolveExistingPrivateAbsolutePath($relativePath);

            if ($absolutePath === null) {
                return null;
            }

            $size = @filesize($absolutePath);

            return [
                'extension' => $extension !== '' ? $extension : null,
                'mime_type' => $this->safeMimeType($absolutePath),
                'size' => is_int($size) ? $size : null,
            ];
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);

        if (!$this->cameraDisk()->exists($diskPath)) {
            return null;
        }

        $mimeType = null;
        $size = null;

        try {
            $resolvedSize = $this->cameraDisk()->size($diskPath);
            $size = is_numeric($resolvedSize) ? (int) $resolvedSize : null;
        } catch (Throwable) {
            $size = null;
        }

        try {
            $resolvedMimeType = $this->cameraDisk()->mimeType($diskPath);
            $mimeType = is_string($resolvedMimeType) && trim($resolvedMimeType) !== ''
                ? trim($resolvedMimeType)
                : null;
        } catch (Throwable) {
            $mimeType = null;
        }

        return [
            'extension' => $extension !== '' ? $extension : null,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    /**
     * @return resource|false
     */
    public function openRecordingReadStream(?string $recordingPath)
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null) {
            return false;
        }

        if (!$this->isCameraRelativePath($relativePath) || !$this->usingNetworkStorage()) {
            $absolutePath = $this->resolveExistingPrivateAbsolutePath($relativePath);

            return $absolutePath !== null ? @fopen($absolutePath, 'rb') : false;
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);

        if (!$this->cameraDisk()->exists($diskPath)) {
            return false;
        }

        try {
            return $this->cameraDisk()->readStream($diskPath);
        } catch (Throwable) {
            return false;
        }
    }

    public function privateFileExists(?string $path): bool
    {
        $relativePath = $this->privateStorageRelativePath($path);

        if ($relativePath === null) {
            return false;
        }

        if ($this->isCameraRelativePath($relativePath)) {
            return $this->usingNetworkStorage()
                ? $this->cameraDisk()->exists($this->cameraDiskRelativePath($relativePath))
                : $this->resolveExistingPrivateAbsolutePath($relativePath) !== null;
        }

        return $this->resolveExistingPrivateAbsolutePath($relativePath) !== null;
    }

    public function writableAbsolutePath(string $privateRelativePath): string
    {
        return $this->localWritablePath($privateRelativePath);
    }

    public function finalizeStagedWrite(string $privateRelativePath, string $localPath, bool $deleteSource = true): void
    {
        $relativePath = $this->privateStorageRelativePath($privateRelativePath);

        if ($relativePath === null || !is_file($localPath)) {
            return;
        }

        if (!$this->isCameraRelativePath($relativePath) || !$this->usingNetworkStorage()) {
            return;
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);
        $this->ensureCameraDiskDirectory(dirname($diskPath));

        $stream = fopen($localPath, 'rb');

        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to open the staged file for SMB upload: '.$localPath);
        }

        try {
            $this->cameraDisk()->writeStream($diskPath, $stream);
        } finally {
            fclose($stream);
        }

        if ($deleteSource) {
            @unlink($localPath);
            $this->pruneEmptyDirectoryTree(dirname($localPath), $this->writeStagingRoot());
        }
    }

    public function deleteTemporaryFile(?string $path): void
    {
        if (!is_string($path) || trim($path) === '') {
            return;
        }

        $normalizedPath = $this->normalizeAbsolutePath($path);

        if ($normalizedPath === null || !$this->isTemporaryManagedPath($normalizedPath)) {
            return;
        }

        if (is_file($normalizedPath)) {
            @unlink($normalizedPath);
        }

        $temporaryRoot = str_starts_with($normalizedPath, $this->readCacheRoot())
            ? $this->readCacheRoot()
            : $this->writeStagingRoot();

        $this->pruneEmptyDirectoryTree(dirname($normalizedPath), $temporaryRoot);
    }

    public function isTemporaryManagedPath(?string $path): bool
    {
        if (!is_string($path) || trim($path) === '') {
            return false;
        }

        $normalizedPath = $this->normalizeAbsolutePath($path);

        if ($normalizedPath === null) {
            return false;
        }

        return $this->pathWithinRoot($normalizedPath, $this->readCacheRoot())
            || $this->pathWithinRoot($normalizedPath, $this->writeStagingRoot());
    }

    /**
     * @return array<int, array<string, int|string|null>>
     */
    public function orphanRecordingFiles(): array
    {
        return $this->usingNetworkStorage()
            ? $this->networkOrphanRecordingFiles()
            : $this->localOrphanRecordingFiles();
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
            $relativePath = $this->normalizePrivateStorageRelativePath(is_string($orphan['relative_path'] ?? null) ? $orphan['relative_path'] : null);
            $reviewAssetsPresent = (bool) ($orphan['review_assets_present'] ?? 0);
            $fileSize = is_numeric($orphan['size_bytes'] ?? null) ? (int) $orphan['size_bytes'] : 0;

            if ($relativePath === null) {
                continue;
            }

            if ($this->deleteRecordingFile($relativePath)) {
                $filesPurged++;
                $bytesFreed += max(0, $fileSize);
            }

            $this->deleteRecordingReviewAssets($relativePath);

            if ($reviewAssetsPresent) {
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
        $relativePath = $this->privateStorageRelativePath($previewPath);

        if ($relativePath === null) {
            return null;
        }

        if ($this->isCameraRelativePath($relativePath) && $this->usingNetworkStorage()) {
            try {
                $mimeType = $this->cameraDisk()->mimeType($this->cameraDiskRelativePath($relativePath));

                return is_string($mimeType) && $mimeType !== '' ? $mimeType : null;
            } catch (\Throwable) {
                return null;
            }
        }

        $absolutePath = $this->resolveExistingPrivateAbsolutePath($relativePath);

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

        $cameraDirectory = (string) $cameraId;

        if (!$this->usingNetworkStorage()) {
            File::deleteDirectory($this->privateAbsolutePath('cameras/'.$cameraDirectory));

            return;
        }

        $this->cameraDisk()->deleteDirectory($cameraDirectory);

        File::deleteDirectory($this->writeStagingAbsolutePath('cameras/'.$cameraDirectory));
        File::deleteDirectory($this->readCacheRoot().'/cameras/'.$cameraDirectory);
    }

    private function localReadablePath(?string $path): ?string
    {
        $relativePath = $this->privateStorageRelativePath($path);

        if ($relativePath === null) {
            return null;
        }

        if (!$this->isCameraRelativePath($relativePath) || !$this->usingNetworkStorage()) {
            return $this->resolveExistingPrivateAbsolutePath($relativePath);
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);

        if (!$this->cameraDisk()->exists($diskPath)) {
            return null;
        }

        $readPath = $this->temporaryReadCachePath($relativePath);
        $readDirectory = dirname($readPath);

        $stream = $this->cameraDisk()->readStream($diskPath);

        if (!is_resource($stream)) {
            return null;
        }

        $destination = $this->openReadCacheDestination($readPath, $readDirectory);

        if (!is_resource($destination)) {
            fclose($stream);

            throw new RuntimeException('Unable to create a local cache file for SMB storage reads.');
        }

        try {
            stream_copy_to_stream($stream, $destination);
        } finally {
            fclose($stream);
            fclose($destination);
        }

        return is_file($readPath) ? $readPath : null;
    }

    private function openReadCacheDestination(string $readPath, string $readDirectory)
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->ensureWritableDirectory($readDirectory);

            $destination = @fopen($readPath, 'xb');

            if (is_resource($destination)) {
                return $destination;
            }

            if (is_file($readPath)) {
                @unlink($readPath);
            }
        }

        return false;
    }

    private function safeMimeType(string $absolutePath): ?string
    {
        try {
            $mimeType = File::mimeType($absolutePath);

            return is_string($mimeType) && trim($mimeType) !== '' ? trim($mimeType) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function localWritablePath(string $privateRelativePath): string
    {
        $relativePath = $this->privateStorageRelativePath($privateRelativePath);

        if ($relativePath === null) {
            throw new RuntimeException('A writable path under private storage is required.');
        }

        $absolutePath = $this->isCameraRelativePath($relativePath) && $this->usingNetworkStorage()
            ? $this->writeStagingAbsolutePath($relativePath)
            : $this->privateAbsolutePath($relativePath);

        $this->ensureWritableDirectory(dirname($absolutePath));

        return $absolutePath;
    }

    private function localOrphanRecordingFiles(): array
    {
        $cameraRoot = $this->privateAbsolutePath('cameras');

        if (!is_dir($cameraRoot)) {
            return [];
        }

        $trackedPaths = $this->trackedRecordingPaths();
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

            $reviewDirectory = $this->reviewAssetDirectoryRelativePath($relativePath);

            $orphans[] = [
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
                'size_bytes' => $file->getSize(),
                'modified_at' => date(DATE_ATOM, $file->getMTime()),
                'review_directory' => $reviewDirectory,
                'review_assets_present' => $this->privateDirectoryExists($reviewDirectory),
            ];
        }

        usort($orphans, fn (array $left, array $right): int => strcmp((string) ($left['modified_at'] ?? ''), (string) ($right['modified_at'] ?? '')));

        return $orphans;
    }

    private function networkOrphanRecordingFiles(): array
    {
        $trackedPaths = $this->trackedRecordingPaths();
        $orphans = [];

        foreach ($this->cameraDisk()->allFiles() as $diskPath) {
            $normalizedDiskPath = str_replace('\\', '/', $diskPath);

            if (!str_contains($normalizedDiskPath, '/recordings/') || str_contains($normalizedDiskPath, '/_review/')) {
                continue;
            }

            $relativePath = 'cameras/'.ltrim($normalizedDiskPath, '/');

            if (isset($trackedPaths[$relativePath])) {
                continue;
            }

            try {
                $size = $this->cameraDisk()->size($normalizedDiskPath);
            } catch (\Throwable) {
                $size = null;
            }

            try {
                $modified = $this->cameraDisk()->lastModified($normalizedDiskPath);
            } catch (\Throwable) {
                $modified = null;
            }

            $reviewDirectory = $this->reviewAssetDirectoryRelativePath($relativePath);

            $orphans[] = [
                'relative_path' => $relativePath,
                'absolute_path' => $relativePath,
                'size_bytes' => is_int($size) ? $size : null,
                'modified_at' => is_int($modified) ? date(DATE_ATOM, $modified) : null,
                'review_directory' => $reviewDirectory,
                'review_assets_present' => $this->privateDirectoryExists($reviewDirectory),
            ];
        }

        usort($orphans, fn (array $left, array $right): int => strcmp((string) ($left['modified_at'] ?? ''), (string) ($right['modified_at'] ?? '')));

        return $orphans;
    }

    /**
     * @return array<string, bool>
     */
    private function trackedRecordingPaths(): array
    {
        return array_fill_keys(
            CameraRecording::query()
                ->whereNotNull('relative_path')
                ->pluck('relative_path')
                ->map(fn ($path): ?string => $this->normalizePrivateStorageRelativePath($path))
                ->filter(fn ($path): bool => is_string($path) && $path !== '')
                ->values()
                ->all(),
            true,
        );
    }

    private function pruneEmptyRecordingDirectories(string $recordingPath): void
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null || !$this->isCameraRelativePath($relativePath)) {
            return;
        }

        if (!$this->usingNetworkStorage()) {
            $absolutePath = $this->privateAbsolutePath($relativePath);
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

            $reviewDirectory = $this->reviewAssetDirectoryRelativePath($relativePath);

            if ($reviewDirectory !== null && $this->privateDirectoryExists($reviewDirectory)) {
                $reviewAbsolutePath = $this->privateAbsolutePath($reviewDirectory);
                $entries = @scandir($reviewAbsolutePath) ?: [];
                $entries = array_values(array_diff($entries, ['.', '..']));

                if ($entries === []) {
                    @rmdir($reviewAbsolutePath);
                }
            }

            return;
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);
        $segments = explode('/', $diskPath);

        if (count($segments) < 3) {
            return;
        }

        $stopDirectory = $segments[0].'/'.$segments[1];
        $directory = dirname($diskPath);

        while ($directory !== '.' && $directory !== $stopDirectory && str_starts_with($directory, $stopDirectory)) {
            if (!$this->cameraDirectoryEmpty($directory)) {
                break;
            }

            $this->cameraDisk()->deleteDirectory($directory);
            $directory = dirname($directory);
        }

        $reviewDirectory = $this->reviewAssetDirectoryRelativePath($relativePath);

        if ($reviewDirectory !== null && $this->privateDirectoryExists($reviewDirectory) && $this->cameraDirectoryEmpty($this->cameraDiskRelativePath($reviewDirectory))) {
            $this->deletePrivateDirectory($reviewDirectory);
        }
    }

    private function cameraDirectoryEmpty(string $diskDirectory): bool
    {
        return $this->cameraDisk()->files($diskDirectory) === []
            && $this->cameraDisk()->directories($diskDirectory) === [];
    }

    private function deletePrivateDirectory(string $relativeDirectory): void
    {
        if ($this->isCameraRelativePath($relativeDirectory)) {
            if (!$this->usingNetworkStorage()) {
                File::deleteDirectory($this->privateAbsolutePath($relativeDirectory));

                return;
            }

            $this->cameraDisk()->deleteDirectory($this->cameraDiskRelativePath($relativeDirectory));

            if ($this->usingNetworkStorage()) {
                File::deleteDirectory($this->writeStagingAbsolutePath($relativeDirectory));
                File::deleteDirectory($this->readCacheRoot().'/'.$relativeDirectory);
            }

            return;
        }

        File::deleteDirectory($this->privateAbsolutePath($relativeDirectory));
    }

    private function privateDirectoryExists(?string $relativeDirectory): bool
    {
        $normalizedDirectory = $this->privateStorageRelativePath($relativeDirectory);

        if ($normalizedDirectory === null) {
            return false;
        }

        if ($this->isCameraRelativePath($normalizedDirectory)) {
            return $this->usingNetworkStorage()
                ? $this->cameraDisk()->directoryExists($this->cameraDiskRelativePath($normalizedDirectory))
                : is_dir($this->privateAbsolutePath($normalizedDirectory));
        }

        return is_dir($this->privateAbsolutePath($normalizedDirectory));
    }

    private function cameraDiskRelativePath(string $relativePath): string
    {
        if (!$this->isCameraRelativePath($relativePath)) {
            throw new RuntimeException('Only camera private storage paths can be mapped onto the camera disk.');
        }

        return ltrim(substr($relativePath, strlen('cameras/')), '/');
    }

    private function isCameraRelativePath(string $relativePath): bool
    {
        return str_starts_with(ltrim($relativePath, '/'), 'cameras/');
    }

    private function cameraDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('camera_private');
    }

    private function privateAbsolutePath(string $relativePath): string
    {
        return storage_path('app/private/'.ltrim($relativePath, '/'));
    }

    private function privateStorageRoot(): string
    {
        return rtrim(str_replace('\\', '/', storage_path('app/private')), '/');
    }

    private function writeStagingRoot(): string
    {
        return rtrim(str_replace('\\', '/', storage_path('app/private/ffmpeg-temp/camera-network-staging')), '/');
    }

    private function readCacheRoot(): string
    {
        return rtrim(str_replace('\\', '/', storage_path('app/private/ffmpeg-temp/camera-network-cache')), '/');
    }

    private function writeStagingAbsolutePath(string $relativePath): string
    {
        return $this->writeStagingRoot().'/'.ltrim($relativePath, '/');
    }

    private function ensureWritableDirectory(string $path): void
    {
        clearstatcache(true, $path);

        if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('The camera storage directory is not writable: '.$path);
        }

        $this->normalizeManagedDirectoryPermissions($path);

        clearstatcache(true, $path);

        if (!is_dir($path) || !is_writable($path)) {
            throw new RuntimeException('The camera storage directory is not writable: '.$path);
        }
    }

    private function normalizeManagedDirectoryPermissions(string $path): void
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');

        if ($normalizedPath === '') {
            return;
        }

        foreach ($this->managedDirectoryStopRoots() as $stopRoot) {
            $normalizedStopRoot = rtrim(str_replace('\\', '/', $stopRoot), '/');

            if (!str_starts_with($normalizedPath, $normalizedStopRoot)) {
                continue;
            }

            $directory = $normalizedPath;

            while ($directory !== '' && str_starts_with($directory, $normalizedStopRoot)) {
                if (is_dir($directory)) {
                    @chmod($directory, 02775);
                }

                if ($directory === $normalizedStopRoot) {
                    break;
                }

                $parent = dirname($directory);

                if ($parent === $directory) {
                    break;
                }

                $directory = rtrim(str_replace('\\', '/', $parent), '/');
            }

            break;
        }
    }

    /**
     * @return array<int, string>
     */
    private function managedDirectoryStopRoots(): array
    {
        return [
            rtrim(str_replace('\\', '/', storage_path('app/private')), '/'),
            rtrim(str_replace('\\', '/', storage_path('app/private/ffmpeg-temp')), '/'),
        ];
    }

    private function ensureCameraDiskDirectory(string $diskDirectory): void
    {
        $normalizedDirectory = trim(str_replace('\\', '/', $diskDirectory), '/.');

        if ($normalizedDirectory === '') {
            return;
        }

        $segments = explode('/', $normalizedDirectory);
        $path = '';
        $disk = $this->cameraDisk();

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $path = $path === '' ? $segment : $path.'/'.$segment;

            if ($this->cameraDiskDirectoryExists($disk, $path)) {
                continue;
            }

            $disk->makeDirectory($path);
        }
    }

    private function cameraDiskDirectoryExists(FilesystemContract $disk, string $path): bool
    {
        if (method_exists($disk, 'directoryExists')) {
            return $disk->directoryExists($path);
        }

        return $disk->files($path) !== [] || $disk->directories($path) !== [];
    }

    private function pruneEmptyDirectoryTree(string $directory, string $stopAt): void
    {
        $normalizedStopAt = rtrim(str_replace('\\', '/', $stopAt), '/');
        $normalizedDirectory = rtrim(str_replace('\\', '/', $directory), '/');

        while ($normalizedDirectory !== '' && $normalizedDirectory !== $normalizedStopAt && str_starts_with($normalizedDirectory, $normalizedStopAt)) {
            $entries = @scandir($normalizedDirectory) ?: [];
            $entries = array_values(array_diff($entries, ['.', '..']));

            if ($entries !== []) {
                break;
            }

            @rmdir($normalizedDirectory);
            $normalizedDirectory = dirname($normalizedDirectory);
        }
    }

    private function reviewAssetDirectoryRelativePath(string $recordingPath): ?string
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

        return ($directory !== '' ? $directory.'/' : '').'_review/'.$baseName;
    }

    private function privateStorageRelativePath(?string $path): ?string
    {
        if (!is_string($path) || trim($path) === '') {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', trim($path));
        $trimmedPath = ltrim($normalizedPath, '/');
        $privateStorageRoot = $this->privateStorageRoot();
        $writeStagingRoot = $this->writeStagingRoot();
        $readCacheRoot = $this->readCacheRoot();

        foreach ([$normalizedPath, $trimmedPath] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            if (in_array($candidate, [$privateStorageRoot, $writeStagingRoot, $readCacheRoot], true)) {
                return null;
            }

            $strippedTemporaryPrefix = false;

            foreach (['camera-network-staging/', 'camera-network-cache/'] as $temporaryPrefix) {
                if (str_starts_with($candidate, $temporaryPrefix)) {
                    $candidate = ltrim(substr($candidate, strlen($temporaryPrefix)), '/');
                    $strippedTemporaryPrefix = true;
                    break;
                }
            }

            if ($strippedTemporaryPrefix) {
                return $this->normalizeRelativeStoragePath($candidate);
            }

            if (str_starts_with($candidate, $writeStagingRoot.'/')) {
                return $this->normalizeRelativeStoragePath(ltrim(substr($candidate, strlen($writeStagingRoot)), '/'));
            }

            if (str_starts_with($candidate, $readCacheRoot.'/')) {
                $relativePath = ltrim(substr($candidate, strlen($readCacheRoot)), '/');

                return $this->normalizeRelativeStoragePath($this->stripReadCacheMarker($relativePath));
            }

            if (str_starts_with($candidate, $privateStorageRoot.'/')) {
                return $this->normalizeRelativeStoragePath(ltrim(substr($candidate, strlen($privateStorageRoot)), '/'));
            }

            foreach (['storage/app/private/', 'app/private/'] as $prefix) {
                if (str_starts_with($candidate, $prefix)) {
                    $relativePath = ltrim(substr($candidate, strlen($prefix)), '/');

                    if (str_starts_with($relativePath, 'ffmpeg-temp/camera-network-cache/')) {
                        $cachedRelativePath = ltrim(substr($relativePath, strlen('ffmpeg-temp/camera-network-cache/')), '/');

                        return $this->normalizeRelativeStoragePath($this->stripReadCacheMarker($cachedRelativePath));
                    }

                    if (str_starts_with($relativePath, 'ffmpeg-temp/camera-network-staging/')) {
                        return $this->normalizeRelativeStoragePath(ltrim(substr($relativePath, strlen('ffmpeg-temp/camera-network-staging/')), '/'));
                    }

                    foreach (['camera-network-staging/', 'camera-network-cache/'] as $temporaryPrefix) {
                        if (str_starts_with($relativePath, $temporaryPrefix)) {
                            return $this->normalizeRelativeStoragePath(ltrim(substr($relativePath, strlen($temporaryPrefix)), '/'));
                        }
                    }

                    return $this->normalizeRelativeStoragePath($relativePath);
                }
            }
        }

        return $this->normalizeRelativeStoragePath($trimmedPath);
    }

    private function resolveExistingPrivateAbsolutePath(string $relativePath): ?string
    {
        $absolutePath = $this->privateAbsolutePath($relativePath);
        $resolvedPath = realpath($absolutePath);

        if (!is_string($resolvedPath) || $resolvedPath === '') {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', $resolvedPath);

        if (!$this->pathWithinRoot($normalizedPath, $this->privateStorageRoot()) || !is_file($normalizedPath)) {
            return null;
        }

        return $normalizedPath;
    }

    private function temporaryReadCachePath(string $relativePath): string
    {
        $normalizedRelativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        $directory = trim(dirname($normalizedRelativePath), './');
        $extension = pathinfo($normalizedRelativePath, PATHINFO_EXTENSION);
        $baseName = pathinfo($normalizedRelativePath, PATHINFO_FILENAME);
        $cacheFileName = $baseName !== ''
            ? $baseName.'.cache_'.uniqid('', false).($extension !== '' ? '.'.$extension : '')
            : basename($normalizedRelativePath).'.cache_'.uniqid('', false);

        return $this->readCacheRoot().'/'.($directory !== '' ? $directory.'/' : '').$cacheFileName;
    }

    private function stripReadCacheMarker(string $path): string
    {
        $withoutInlineMarker = preg_replace('/([^\/]+)\.cache_[^\/.]+(\.[^\/]+)$/', '$1$2', $path) ?: $path;

        return preg_replace('/\.cache_[^\/]+$/', '', $withoutInlineMarker) ?: $withoutInlineMarker;
    }

    private function normalizeRelativeStoragePath(?string $path): ?string
    {
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            return null;
        }

        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        $normalizedPath = implode('/', $segments);

        return $normalizedPath !== '' ? $normalizedPath : null;
    }

    private function normalizeAbsolutePath(?string $path): ?string
    {
        if (!is_string($path) || trim($path) === '' || str_contains($path, "\0")) {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', trim($path));
        $isAbsolute = str_starts_with($normalizedPath, '/');
        $segments = [];

        foreach (explode('/', $normalizedPath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $collapsedPath = implode('/', $segments);

        if ($collapsedPath === '') {
            return $isAbsolute ? '/' : null;
        }

        return ($isAbsolute ? '/' : '').$collapsedPath;
    }

    private function pathWithinRoot(string $path, string $root): bool
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');

        return $normalizedPath === $normalizedRoot
            || str_starts_with($normalizedPath, $normalizedRoot.'/');
    }
}