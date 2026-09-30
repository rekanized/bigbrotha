<?php

namespace App\Services;

use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\Concerns\ResolvesConfiguredBinaries;
use FilesystemIterator;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class CameraStorageService
{
    use ResolvesConfiguredBinaries;

    public const RECORDING_AVAILABILITY_PRESENT = 'present';

    public const RECORDING_AVAILABILITY_MISSING = 'missing';

    public const RECORDING_AVAILABILITY_UNREACHABLE = 'unreachable';

    private const PREVIEW_READ_TIMEOUT_SECONDS = 3;

    private const SMB_METADATA_TIMEOUT_SECONDS = 3;

    private const SMB_AUTH_DIRECTORY = 'smb-runtime';

    private const REVIEW_ASSET_READ_TIMEOUT_SECONDS = 3;

    public function __construct(
        private readonly ApplicationSettingsService $settings,
    ) {}

    public function usingNetworkStorage(): bool
    {
        return $this->settings->networkStorageEnabled();
    }

    public function ensureCameraDirectories(Camera $camera): string
    {
        $cameraId = $camera->getKey();

        if (! is_int($cameraId) && ! is_string($cameraId)) {
            throw new RuntimeException('The camera must be saved before its storage directories can be prepared.');
        }

        $cameraDirectory = (string) $cameraId;

        if ($this->usingNetworkStorage()) {
            $localCameraRoot = $this->privateAbsolutePath('cameras/'.$cameraDirectory);

            foreach ([$localCameraRoot, $localCameraRoot.'/previews', $localCameraRoot.'/recordings'] as $path) {
                $this->ensureWritableDirectory($path);
            }

            $stagingRoot = $this->writeStagingAbsolutePath('cameras/'.$cameraDirectory);

            foreach ([$stagingRoot, $stagingRoot.'/recordings'] as $path) {
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

        if (! is_int($cameraId) && ! is_string($cameraId)) {
            throw new RuntimeException('The camera must be saved before a preview path can be generated.');
        }

        return 'cameras/'.(string) $cameraId.'/previews/'.$fileName;
    }

    public function resolvePreviewAbsolutePath(?string $previewPath): ?string
    {
        return $this->localReadablePath($previewPath, self::PREVIEW_READ_TIMEOUT_SECONDS);
    }

    /**
     * @return array{absolute_path: string, mime_type: string}|null
     */
    public function resolvePreviewImage(?string $previewPath): ?array
    {
        $absolutePath = $this->resolvePreviewAbsolutePath($previewPath);

        if ($absolutePath === null) {
            return null;
        }

        $mimeType = $this->imageMimeTypeFromAbsolutePath($absolutePath);

        if ($mimeType === null) {
            $this->deleteTemporaryFile($absolutePath);

            return null;
        }

        return [
            'absolute_path' => $absolutePath,
            'mime_type' => $mimeType,
        ];
    }

    public function recordingAbsolutePath(Camera $camera, \DateTimeInterface $timestamp, string $fileName): string
    {
        $cameraId = $camera->getKey();

        if (! is_int($cameraId) && ! is_string($cameraId)) {
            throw new RuntimeException('The camera must be saved before a recording path can be generated.');
        }

        return $this->localWritablePath('cameras/'.(string) $cameraId.'/recordings/'.$timestamp->format('Y/m/d').'/'.$fileName);
    }

    public function normalizePrivateStorageRelativePath(?string $path): ?string
    {
        return $this->privateStorageRelativePath($path);
    }

    public function pathUsesNetworkStorage(?string $path): bool
    {
        $relativePath = $this->privateStorageRelativePath($path);

        return is_string($relativePath) && $this->usesNetworkCameraStorage($relativePath);
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

    public function reviewSpritesStoredLocally(): bool
    {
        return (bool) config('recording.review_assets.store_sprites_locally', true);
    }

    public function recordingLocalReviewSpriteRelativePath(?string $recordingPath, string $fileName = 'scrub-sprite.jpg'): ?string
    {
        $normalizedPath = $this->privateStorageRelativePath($recordingPath);
        $reviewDirectory = $normalizedPath !== null ? $this->reviewAssetDirectoryRelativePath($normalizedPath) : null;

        if ($reviewDirectory === null) {
            return null;
        }

        return 'review-sprites/'.$reviewDirectory.'/'.ltrim($fileName, '/');
    }

    public function recordingLocalReviewSpriteAbsolutePath(?string $recordingPath, string $fileName = 'scrub-sprite.jpg', bool $ensureDirectory = false): ?string
    {
        $relativePath = $this->recordingLocalReviewSpriteRelativePath($recordingPath, $fileName);

        if ($relativePath === null) {
            return null;
        }

        return $ensureDirectory
            ? $this->localWritablePath($relativePath)
            : $this->localReadablePath($relativePath);
    }

    public function recordingReviewAssetAbsolutePath(?string $recordingPath, string $fileName, bool $ensureDirectory = false): ?string
    {
        $relativePath = $this->recordingReviewAssetRelativePath($recordingPath, $fileName);

        if ($relativePath === null) {
            return null;
        }

        return $ensureDirectory
            ? $this->localWritablePath($relativePath)
            : $this->localReadablePath($relativePath, self::REVIEW_ASSET_READ_TIMEOUT_SECONDS);
    }

    public function resolveReviewAssetAbsolutePath(?string $assetPath): ?string
    {
        return $this->localReadablePath($assetPath, self::REVIEW_ASSET_READ_TIMEOUT_SECONDS);
    }

    public function deleteRecordingReviewAssets(?string $recordingPath): void
    {
        $normalizedPath = $this->privateStorageRelativePath($recordingPath);
        $reviewDirectory = $normalizedPath !== null ? $this->reviewAssetDirectoryRelativePath($normalizedPath) : null;
        $localSpriteDirectory = $normalizedPath !== null ? $this->localReviewSpriteDirectoryRelativePath($normalizedPath) : null;

        if ($reviewDirectory === null && $localSpriteDirectory === null) {
            return;
        }

        if ($reviewDirectory !== null) {
            $this->deletePrivateDirectory($reviewDirectory);
        }

        if ($localSpriteDirectory !== null) {
            $this->deletePrivateDirectory($localSpriteDirectory);
        }
    }

    public function deleteRecordingFile(?string $recordingPath): bool
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null) {
            return true;
        }

        if ($this->usesNetworkCameraStorage($relativePath)) {
            $diskPath = $this->cameraDiskRelativePath($relativePath);
            $availability = $this->networkCameraDiskAvailability($diskPath);

            if ($availability === self::RECORDING_AVAILABILITY_MISSING) {
                $this->pruneEmptyRecordingDirectoriesSafely($relativePath);

                return true;
            }

            $smbClientDeleted = $this->deleteCameraDiskPathWithSmbClient($diskPath);

            if (is_bool($smbClientDeleted)) {
                if ($smbClientDeleted) {
                    $this->pruneEmptyRecordingDirectoriesSafely($relativePath);
                }

                return $smbClientDeleted;
            }

            $deleted = $this->cameraDisk()->delete($diskPath);
            $missing = $deleted || $this->networkCameraDiskAvailability($diskPath) === self::RECORDING_AVAILABILITY_MISSING;

            if ($missing) {
                $this->pruneEmptyRecordingDirectoriesSafely($relativePath);
            }

            return $missing;
        }

        if ($this->isCameraRelativePath($relativePath)) {
            $absolutePath = $this->privateAbsolutePath($relativePath);

            if (! is_file($absolutePath)) {
                $this->pruneEmptyRecordingDirectories($relativePath);

                return true;
            }

            $deleted = @unlink($absolutePath);
            clearstatcache(true, $absolutePath);
            $this->pruneEmptyRecordingDirectories($relativePath);

            return $deleted || ! is_file($absolutePath);
        }

        $absolutePath = $this->privateAbsolutePath($relativePath);

        if (! is_file($absolutePath)) {
            return true;
        }

        $deleted = Storage::disk('local')->delete($relativePath);

        clearstatcache(true, $absolutePath);

        return $deleted || ! is_file($absolutePath);
    }

    public function resolveRecordingAbsolutePath(?string $recordingPath, ?int $transferTimeoutSeconds = null): ?string
    {
        return $this->localReadablePath($recordingPath, $transferTimeoutSeconds);
    }

    /**
     * @return array{relative_path: string|null, storage_mode: string, absolute_path: string|null, disk_path: string|null, smb_target_path: string|null, cache_path: string|null}
     */
    public function recordingLookupContext(?string $recordingPath): array
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null) {
            return [
                'relative_path' => null,
                'storage_mode' => $this->usingNetworkStorage() ? 'network' : 'local',
                'absolute_path' => null,
                'disk_path' => null,
                'smb_target_path' => null,
                'cache_path' => null,
            ];
        }

        if (! $this->usesNetworkCameraStorage($relativePath)) {
            return [
                'relative_path' => $relativePath,
                'storage_mode' => 'local',
                'absolute_path' => $this->privateAbsolutePath($relativePath),
                'disk_path' => null,
                'smb_target_path' => null,
                'cache_path' => null,
            ];
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);

        return [
            'relative_path' => $relativePath,
            'storage_mode' => 'network',
            'absolute_path' => null,
            'disk_path' => $diskPath,
            'smb_target_path' => $this->cameraDiskSmbTargetPath($diskPath),
            'cache_path' => $this->temporaryReadCachePath($relativePath),
        ];
    }

    public function missingRecordingSegmentMessage(?string $recordingPath): string
    {
        $context = $this->recordingLookupContext($recordingPath);
        $parts = ['The saved recording segment is not available on disk.'];

        if (is_string($context['relative_path']) && $context['relative_path'] !== '') {
            $parts[] = 'relative_path='.$context['relative_path'];
        }

        $parts[] = 'storage_mode='.$context['storage_mode'];

        if (is_string($context['absolute_path']) && $context['absolute_path'] !== '') {
            $parts[] = 'absolute_path='.$context['absolute_path'];
        }

        if (is_string($context['disk_path']) && $context['disk_path'] !== '') {
            $parts[] = 'disk_path='.$context['disk_path'];
        }

        if (is_string($context['smb_target_path']) && $context['smb_target_path'] !== '') {
            $parts[] = 'smb_target_path='.$context['smb_target_path'];
        }

        if (is_string($context['cache_path']) && $context['cache_path'] !== '') {
            $parts[] = 'cache_path='.$context['cache_path'];
        }

        return implode(' ', $parts);
    }

    public function uploadVerificationFailureMessage(string $baseMessage, string $diskPath, ?string $localPath = null, ?string $availability = null): string
    {
        $parts = [rtrim($baseMessage)];
        $parts[] = 'relative_path=cameras/'.ltrim($diskPath, '/');
        $parts[] = 'disk_path='.$diskPath;

        $smbTargetPath = $this->cameraDiskSmbTargetPath($diskPath);

        if (is_string($smbTargetPath) && $smbTargetPath !== '') {
            $parts[] = 'smb_target_path='.$smbTargetPath;
        }

        if (is_string($localPath) && $localPath !== '') {
            $parts[] = 'local_path='.str_replace('\\', '/', $localPath);
        }

        if (is_string($availability) && $availability !== '') {
            $parts[] = 'availability='.$availability;
        }

        return implode(' ', $parts);
    }

    public function recordingAvailability(?string $recordingPath): string
    {
        $relativePath = $this->privateStorageRelativePath($recordingPath);

        if ($relativePath === null) {
            return self::RECORDING_AVAILABILITY_MISSING;
        }

        if (! $this->usesNetworkCameraStorage($relativePath)) {
            return $this->privateFileAvailability($relativePath);
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);
        $availability = $this->networkCameraDiskAvailability($diskPath);

        if ($availability === self::RECORDING_AVAILABILITY_PRESENT) {
            return $availability;
        }

        return $this->recoverMissingNetworkBackedRecordingFromStaging($relativePath, $diskPath)
            ? self::RECORDING_AVAILABILITY_PRESENT
            : $availability;
    }

    public function recordingExists(?string $recordingPath): bool
    {
        return $this->recordingAvailability($recordingPath) === self::RECORDING_AVAILABILITY_PRESENT;
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

        if (! $this->usesNetworkCameraStorage($relativePath)) {
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

        if ($this->networkCameraDiskAvailability($diskPath) !== self::RECORDING_AVAILABILITY_PRESENT) {
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

        $size ??= $this->cameraDiskFileSizeWithSmbClient($diskPath);

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

        if (! $this->usesNetworkCameraStorage($relativePath)) {
            $absolutePath = $this->resolveExistingPrivateAbsolutePath($relativePath);

            return $absolutePath !== null ? @fopen($absolutePath, 'rb') : false;
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);

        try {
            return $this->cameraDisk()->readStream($diskPath);
        } catch (Throwable) {
            return false;
        }
    }

    public function privateFileExists(?string $path): bool
    {
        return $this->privateFileAvailability($path) === self::RECORDING_AVAILABILITY_PRESENT;
    }

    public function privateFileAvailability(?string $path): string
    {
        $relativePath = $this->privateStorageRelativePath($path);

        if ($relativePath === null) {
            return self::RECORDING_AVAILABILITY_MISSING;
        }

        if ($this->usesNetworkCameraStorage($relativePath)) {
            return $this->networkCameraDiskAvailability($this->cameraDiskRelativePath($relativePath));
        }

        if ($this->isCameraRelativePath($relativePath)) {
            return $this->resolveExistingPrivateAbsolutePath($relativePath) !== null
                ? self::RECORDING_AVAILABILITY_PRESENT
                : self::RECORDING_AVAILABILITY_MISSING;
        }

        return $this->resolveExistingPrivateAbsolutePath($relativePath) !== null
            ? self::RECORDING_AVAILABILITY_PRESENT
            : self::RECORDING_AVAILABILITY_MISSING;
    }

    public function writableAbsolutePath(string $privateRelativePath): string
    {
        return $this->localWritablePath($privateRelativePath);
    }

    public function finalizeStagedWrite(string $privateRelativePath, string $localPath, bool $deleteSource = true): void
    {
        $relativePath = $this->privateStorageRelativePath($privateRelativePath);

        if ($relativePath === null || ! is_file($localPath)) {
            return;
        }

        if (! $this->usesNetworkCameraStorage($relativePath)) {
            return;
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);
        $this->ensureCameraDiskDirectory(dirname($diskPath));

        if ($this->shouldUseSmbClientTransfers()) {
            if (! $this->writeCameraDiskPathWithSmbClient($diskPath, $localPath)) {
                throw new RuntimeException('Unable to upload the staged file to SMB storage: '.$localPath);
            }
        } else {
            $this->writeCameraDiskPathWithAdapter($diskPath, $localPath);
        }

        $this->verifyCameraDiskWrite($diskPath, $localPath);

        if ($deleteSource) {
            @unlink($localPath);
            $this->pruneEmptyDirectoryTree(dirname($localPath), $this->writeStagingRoot());
        }
    }

    public function deleteTemporaryFile(?string $path): void
    {
        if (! is_string($path) || trim($path) === '') {
            return;
        }

        $normalizedPath = $this->normalizeAbsolutePath($path);

        if ($normalizedPath === null || ! $this->isTemporaryManagedPath($normalizedPath)) {
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
        if (! is_string($path) || trim($path) === '') {
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

    private function detectPreviewMimeType(?string $previewPath): ?string
    {
        $relativePath = $this->privateStorageRelativePath($previewPath);

        if ($relativePath === null) {
            return null;
        }

        if ($this->usesNetworkCameraStorage($relativePath)) {
            $absolutePath = $this->resolvePreviewAbsolutePath($relativePath);

            if ($absolutePath === null) {
                return null;
            }

            return $this->imageMimeTypeFromAbsolutePath($absolutePath);
        }

        $absolutePath = $this->resolveExistingPrivateAbsolutePath($relativePath);

        if ($absolutePath === null) {
            return null;
        }

        return $this->imageMimeTypeFromAbsolutePath($absolutePath);
    }

    public function hasUsablePreview(?string $previewPath): bool
    {
        $relativePath = $this->privateStorageRelativePath($previewPath);

        if ($relativePath === null) {
            return false;
        }

        if ($this->usesNetworkCameraStorage($relativePath)) {
            return true;
        }

        return $this->detectPreviewMimeType($previewPath) !== null;
    }

    public function deleteCameraDirectories(Camera $camera): void
    {
        $cameraId = $camera->getKey();

        if (! is_int($cameraId) && ! is_string($cameraId)) {
            return;
        }

        $cameraDirectory = (string) $cameraId;

        if (! $this->usingNetworkStorage()) {
            File::deleteDirectory($this->privateAbsolutePath('cameras/'.$cameraDirectory));
            File::deleteDirectory($this->privateAbsolutePath('review-sprites/cameras/'.$cameraDirectory));

            return;
        }

        if (! $this->cameraDisk()->deleteDirectory($cameraDirectory)) {
            throw new RuntimeException('Unable to delete the camera directory from the active network share.');
        }

        File::deleteDirectory($this->privateAbsolutePath('cameras/'.$cameraDirectory));
        File::deleteDirectory($this->writeStagingAbsolutePath('cameras/'.$cameraDirectory));
        File::deleteDirectory($this->readCacheRoot().'/cameras/'.$cameraDirectory);
        File::deleteDirectory($this->privateAbsolutePath('review-sprites/cameras/'.$cameraDirectory));
    }

    private function localReadablePath(?string $path, ?int $transferTimeoutSeconds = null): ?string
    {
        $relativePath = $this->privateStorageRelativePath($path);

        if ($relativePath === null) {
            return null;
        }

        if (! $this->usesNetworkCameraStorage($relativePath)) {
            return $this->resolveExistingPrivateAbsolutePath($relativePath);
        }

        $diskPath = $this->cameraDiskRelativePath($relativePath);

        if ($this->networkCameraDiskAvailability($diskPath) !== self::RECORDING_AVAILABILITY_PRESENT
            && ! $this->recoverMissingNetworkBackedRecordingFromStaging($relativePath, $diskPath)) {
            return null;
        }

        return $this->copyCameraDiskPathToReadCache($diskPath, $relativePath, $transferTimeoutSeconds);
    }

    private function recoverMissingNetworkBackedRecordingFromStaging(string $relativePath, ?string $diskPath = null): bool
    {
        if (! $this->usesNetworkCameraStorage($relativePath)) {
            return false;
        }

        $stagedPath = $this->writeStagingAbsolutePath($relativePath);

        if (! is_file($stagedPath)) {
            return false;
        }

        try {
            // A publisher may still be using this completed staged clip for review
            // assets. Recovery must never remove another process's input file.
            $this->finalizeStagedWrite($relativePath, $stagedPath, false);
        } catch (Throwable) {
            return false;
        }

        return $this->networkCameraDiskAvailability($diskPath ?? $this->cameraDiskRelativePath($relativePath))
            === self::RECORDING_AVAILABILITY_PRESENT;
    }

    private function copyCameraDiskPathToReadCache(string $diskPath, string $relativePath, ?int $transferTimeoutSeconds = null): ?string
    {
        $smbClientReadPath = $this->copyCameraDiskPathToReadCacheWithSmbClient($diskPath, $relativePath, $transferTimeoutSeconds);

        if (is_string($smbClientReadPath)) {
            return $smbClientReadPath;
        }

        if ($smbClientReadPath === false) {
            return null;
        }

        $readPath = $this->temporaryReadCachePath($relativePath);
        $readDirectory = dirname($readPath);

        try {
            $stream = $this->cameraDisk()->readStream($diskPath);
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        $destination = $this->openReadCacheDestination($readPath, $readDirectory);

        if (! is_resource($destination)) {
            fclose($stream);

            throw new RuntimeException('Unable to create a local cache file for SMB storage reads.');
        }

        try {
            $copied = stream_copy_to_stream($stream, $destination);
        } catch (Throwable $exception) {
            @unlink($readPath);

            throw $exception;
        } finally {
            fclose($stream);
            fclose($destination);
        }

        if ($copied === false) {
            @unlink($readPath);

            return null;
        }

        return is_file($readPath) ? $readPath : null;
    }

    private function copyCameraDiskPathToReadCacheWithSmbClient(string $diskPath, string $relativePath, ?int $transferTimeoutSeconds = null): string|false|null
    {
        if (! $this->shouldUseSmbClientTransfers()) {
            return null;
        }

        $networkConfig = $this->settings->networkStorageDiskConfig();

        if (! is_array($networkConfig)) {
            return null;
        }

        $binary = $this->smbClientBinary();

        if ($binary === null) {
            return null;
        }

        $targetPaths = $this->cameraDiskReadSmbTargetPaths($diskPath);

        if ($targetPaths === []) {
            return false;
        }

        $readPath = $this->temporaryReadCachePath($relativePath);
        $readDirectory = dirname($readPath);

        $this->ensureWritableDirectory($readDirectory);

        if (is_file($readPath)) {
            @unlink($readPath);
        }

        foreach ($targetPaths as $targetPath) {
            try {
                $process = $this->runSmbClientCommand(
                    $binary,
                    $networkConfig,
                    'get "'.$targetPath.'" "'.$readPath.'"',
                    max(15, $transferTimeoutSeconds ?? 20),
                );
            } catch (Throwable) {
                @unlink($readPath);

                continue;
            }

            if ($process->isSuccessful() && is_file($readPath)) {
                return $readPath;
            }

            @unlink($readPath);
        }

        return false;
    }

    private function writeCameraDiskPathWithSmbClient(string $diskPath, string $localPath, ?int $transferTimeoutSeconds = null): bool
    {
        $networkConfig = $this->settings->networkStorageDiskConfig();

        if (! is_array($networkConfig)) {
            return false;
        }

        $binary = $this->smbClientBinary();

        if ($binary === null) {
            return false;
        }

        $targetPath = $this->cameraDiskSmbTargetPath($diskPath);

        if (! is_string($targetPath) || $targetPath === '') {
            return false;
        }

        $localSize = @filesize($localPath);

        if (is_int($localSize) && $localSize >= 0 && $this->cameraDiskFileSizeWithSmbClient($diskPath) === $localSize) {
            return true;
        }

        try {
            $temporaryTargetPath = $targetPath.'.uploading-'.bin2hex(random_bytes(8));
        } catch (Throwable) {
            return false;
        }

        $sizeBasedTimeout = is_int($localSize)
            ? min(180, max(30, (int) ceil($localSize / 1048576)))
            : 30;
        $timeout = max(20, $transferTimeoutSeconds ?? $sizeBasedTimeout);

        try {
            $upload = $this->runSmbClientCommand(
                $binary,
                $networkConfig,
                'put "'.$localPath.'" "'.$temporaryTargetPath.'"',
                $timeout,
            );

            if (! $upload->isSuccessful()) {
                return false;
            }

            $uploadedSize = $this->smbClientTargetFileSize($binary, $networkConfig, $temporaryTargetPath);

            if (! is_int($localSize) || $uploadedSize !== $localSize) {
                return false;
            }

            $promote = $this->runSmbClientCommand(
                $binary,
                $networkConfig,
                'rename "'.$temporaryTargetPath.'" "'.$targetPath.'"',
                $timeout,
            );

            if ($promote->isSuccessful()) {
                return true;
            }

            $backupTargetPath = $targetPath.'.replacing-'.bin2hex(random_bytes(8));
            $backup = $this->runSmbClientCommand(
                $binary,
                $networkConfig,
                'rename "'.$targetPath.'" "'.$backupTargetPath.'"',
                $timeout,
            );

            if (! $backup->isSuccessful()) {
                return false;
            }

            $replace = $this->runSmbClientCommand(
                $binary,
                $networkConfig,
                'rename "'.$temporaryTargetPath.'" "'.$targetPath.'"',
                $timeout,
            );

            if (! $replace->isSuccessful()) {
                $this->runSmbClientCommand($binary, $networkConfig, 'rename "'.$backupTargetPath.'" "'.$targetPath.'"', $timeout);

                return false;
            }

            $this->runSmbClientCommand($binary, $networkConfig, 'del "'.$backupTargetPath.'"', min($timeout, 10));

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            try {
                $this->runSmbClientCommand(
                    $binary,
                    $networkConfig,
                    'del "'.$temporaryTargetPath.'"',
                    min($timeout, 10),
                );
            } catch (Throwable) {
                // Best-effort cleanup only. The staged local source remains available for retry.
            }
        }
    }

    private function writeCameraDiskPathWithAdapter(string $diskPath, string $localPath): void
    {
        $disk = $this->cameraDisk();
        $temporaryPath = $diskPath.'.uploading-'.bin2hex(random_bytes(8));
        $localSize = @filesize($localPath);

        if (! is_int($localSize)) {
            throw new RuntimeException('Unable to determine the staged recording size before SMB upload.');
        }

        $stream = @fopen($localPath, 'rb');

        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open the staged file for SMB upload: '.$localPath);
        }

        try {
            try {
                if (! $disk->writeStream($temporaryPath, $stream)) {
                    throw new RuntimeException('Unable to upload the staged file to SMB storage.');
                }
            } finally {
                fclose($stream);
            }

            if ($disk->size($temporaryPath) !== $localSize) {
                throw new RuntimeException('The temporary SMB upload size does not match the staged file.');
            }

            if ($disk->move($temporaryPath, $diskPath)) {
                return;
            }

            if (! $disk->exists($diskPath)) {
                throw new RuntimeException('Unable to promote the temporary SMB upload.');
            }

            $backupPath = $diskPath.'.replacing-'.bin2hex(random_bytes(8));

            if (! $disk->move($diskPath, $backupPath)) {
                throw new RuntimeException('Unable to preserve the existing SMB file before replacement.');
            }

            if (! $disk->move($temporaryPath, $diskPath)) {
                $disk->move($backupPath, $diskPath);

                throw new RuntimeException('Unable to promote the replacement SMB upload.');
            }

            $disk->delete($backupPath);
        } finally {
            $disk->delete($temporaryPath);
        }
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
                return false;
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

        $absolutePath = $this->usesNetworkCameraStorage($relativePath)
            ? $this->writeStagingAbsolutePath($relativePath)
            : $this->privateAbsolutePath($relativePath);

        $this->ensureWritableDirectory(dirname($absolutePath));

        return $absolutePath;
    }

    private function localOrphanRecordingFiles(): array
    {
        $cameraRoot = $this->privateAbsolutePath('cameras');

        if (! is_dir($cameraRoot)) {
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

            if (! str_contains($absolutePath, '/recordings/') || str_contains($absolutePath, '/_review/')) {
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

            if (! str_contains($normalizedDiskPath, '/recordings/') || str_contains($normalizedDiskPath, '/_review/')) {
                continue;
            }

            $relativePath = 'cameras/'.ltrim($normalizedDiskPath, '/');

            if (isset($trackedPaths[$relativePath])) {
                continue;
            }

            try {
                $size = $this->cameraDisk()->size($normalizedDiskPath);
            } catch (Throwable) {
                $size = null;
            }

            try {
                $modified = $this->cameraDisk()->lastModified($normalizedDiskPath);
            } catch (Throwable) {
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

        if ($relativePath === null || ! $this->isCameraRelativePath($relativePath)) {
            return;
        }

        if (! $this->usesNetworkCameraStorage($relativePath)) {
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
            if (! $this->cameraDirectoryEmpty($directory)) {
                break;
            }

            $this->cameraDisk()->deleteDirectory($directory);
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
    }

    private function pruneEmptyRecordingDirectoriesSafely(string $recordingPath): void
    {
        try {
            $this->pruneEmptyRecordingDirectories($recordingPath);
        } catch (Throwable) {
            // Removing empty parent directories is optional and must not fail a completed file operation.
        }
    }

    private function cameraDirectoryEmpty(string $diskDirectory): bool
    {
        return $this->cameraDisk()->files($diskDirectory) === []
            && $this->cameraDisk()->directories($diskDirectory) === [];
    }

    private function deletePrivateDirectory(string $relativeDirectory): void
    {
        if ($this->usesNetworkCameraStorage($relativeDirectory)) {
            $this->cameraDisk()->deleteDirectory($this->cameraDiskRelativePath($relativeDirectory));

            File::deleteDirectory($this->writeStagingAbsolutePath($relativeDirectory));
            File::deleteDirectory($this->readCacheRoot().'/'.$relativeDirectory);

            return;
        }

        if ($this->isCameraRelativePath($relativeDirectory)) {
            File::deleteDirectory($this->privateAbsolutePath($relativeDirectory));

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

        if ($this->usesNetworkCameraStorage($normalizedDirectory)) {
            return $this->cameraDiskDirectoryExists($this->cameraDisk(), $this->cameraDiskRelativePath($normalizedDirectory));
        }

        if ($this->isCameraRelativePath($normalizedDirectory)) {
            return is_dir($this->privateAbsolutePath($normalizedDirectory));
        }

        return is_dir($this->privateAbsolutePath($normalizedDirectory));
    }

    private function cameraDiskRelativePath(string $relativePath): string
    {
        if (! $this->usesNetworkCameraStorage($relativePath)) {
            throw new RuntimeException('Only network-backed camera recording clip paths can be mapped onto the camera disk.');
        }

        return ltrim(substr($relativePath, strlen('cameras/')), '/');
    }

    private function cameraDiskSmbTargetPath(string $diskPath): ?string
    {
        $networkConfig = $this->settings->networkStorageDiskConfig();

        if (! is_array($networkConfig) || ! $this->isSafeSmbClientPath($diskPath)) {
            return null;
        }

        return trim(($networkConfig['root'] !== '' ? $networkConfig['root'].'/' : '').ltrim($diskPath, '/'), '/');
    }

    /**
     * @return array<int, string>
     */
    private function cameraDiskReadSmbTargetPaths(string $diskPath): array
    {
        $targets = [];
        $primaryTarget = $this->cameraDiskSmbTargetPath($diskPath);

        if (is_string($primaryTarget) && $primaryTarget !== '') {
            $targets[] = $primaryTarget;
        }

        $networkConfig = $this->settings->networkStorageDiskConfig();

        if (! is_array($networkConfig)) {
            return $targets;
        }

        $root = trim(str_replace('\\', '/', (string) ($networkConfig['root'] ?? '')), '/');

        if ($root === '' || preg_match('#(?:^|/)cameras$#i', $root) !== 1) {
            return $targets;
        }

        $legacyRoot = trim((string) preg_replace('#(?:^|/)cameras$#i', '', $root), '/');
        $legacyTarget = trim(($legacyRoot !== '' ? $legacyRoot.'/' : '').ltrim($diskPath, '/'), '/');

        if ($legacyTarget !== '' && $this->isSafeSmbClientPath($legacyTarget)) {
            $targets[] = $legacyTarget;
        }

        return array_values(array_unique($targets));
    }

    protected function verifyCameraDiskWrite(string $diskPath, string $localPath): void
    {
        try {
            $availability = $this->networkCameraDiskAvailability($diskPath);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                $this->uploadVerificationFailureMessage('Unable to verify the uploaded file on the active camera storage disk.', $diskPath, $localPath),
                previous: $exception,
            );
        }

        if ($availability !== self::RECORDING_AVAILABILITY_PRESENT) {
            throw new RuntimeException(
                $this->uploadVerificationFailureMessage('The uploaded file is not visible on the active camera storage disk yet.', $diskPath, $localPath, $availability),
            );
        }

        try {
            $remoteSize = $this->cameraDisk()->size($diskPath);
        } catch (Throwable $exception) {
            $remoteSize = $this->cameraDiskFileSizeWithSmbClient($diskPath);

            if (! is_int($remoteSize)) {
                throw new RuntimeException(
                    $this->uploadVerificationFailureMessage('Unable to verify the uploaded file size on the active camera storage disk.', $diskPath, $localPath),
                    previous: $exception,
                );
            }
        }

        clearstatcache(true, $localPath);
        $localSize = @filesize($localPath);

        if (! is_int($localSize)) {
            throw new RuntimeException(
                $this->uploadVerificationFailureMessage('Unable to verify the staged file size after upload.', $diskPath, $localPath),
            );
        }

        if (! is_numeric($remoteSize) || (int) $remoteSize !== $localSize) {
            throw new RuntimeException(
                $this->uploadVerificationFailureMessage('The uploaded file size on the active camera storage disk does not match the staged file.', $diskPath, $localPath),
            );
        }
    }

    private function isCameraRelativePath(string $relativePath): bool
    {
        return str_starts_with(ltrim($relativePath, '/'), 'cameras/');
    }

    private function usesNetworkCameraStorage(string $relativePath): bool
    {
        return $this->usingNetworkStorage()
            && $this->isNetworkBackedCameraRelativePath($relativePath);
    }

    private function isNetworkBackedCameraRelativePath(string $relativePath): bool
    {
        return preg_match(
            '#^cameras/[^/]+/recordings/(?:\d{4}/\d{2}/\d{2}/)?[^/]+$#',
            ltrim($relativePath, '/'),
        ) === 1;
    }

    protected function cameraDisk(): Filesystem
    {
        return Storage::disk('camera_private');
    }

    protected function networkCameraDiskAvailability(string $diskPath): string
    {
        $disk = $this->cameraDisk();
        $encounteredException = false;

        $smbClientAvailability = $this->cameraDiskFileAvailabilityWithSmbClient($diskPath);

        if ($smbClientAvailability !== null) {
            return $smbClientAvailability;
        }

        try {
            if ($disk->exists($diskPath)) {
                return self::RECORDING_AVAILABILITY_PRESENT;
            }
        } catch (Throwable) {
            $encounteredException = true;
        }

        try {
            $size = $disk->size($diskPath);

            if (is_numeric($size) && (int) $size >= 0) {
                return self::RECORDING_AVAILABILITY_PRESENT;
            }
        } catch (Throwable) {
            $encounteredException = true;
        }

        $parentDirectory = dirname($diskPath);
        $lookupDirectory = $parentDirectory === '.' ? '' : $parentDirectory;

        try {
            $siblings = array_map(
                static fn (string $path): string => str_replace('\\', '/', $path),
                $disk->files($lookupDirectory),
            );

            if (in_array($diskPath, $siblings, true) || in_array(basename($diskPath), array_map('basename', $siblings), true)) {
                return self::RECORDING_AVAILABILITY_PRESENT;
            }
        } catch (Throwable) {
            $encounteredException = true;
        }

        return $encounteredException || $this->shouldUseSmbClientTransfers()
            ? self::RECORDING_AVAILABILITY_UNREACHABLE
            : self::RECORDING_AVAILABILITY_MISSING;
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

        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('The camera storage directory is not writable: '.$path);
        }

        $this->normalizeManagedDirectoryPermissions($path);

        clearstatcache(true, $path);

        if (! is_dir($path) || ! is_writable($path)) {
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

            if (! str_starts_with($normalizedPath, $normalizedStopRoot)) {
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

        if ($this->cameraDiskDirectoryExists($disk, $normalizedDirectory)) {
            return;
        }

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $path = $path === '' ? $segment : $path.'/'.$segment;

            if ($this->cameraDiskDirectoryExists($disk, $path)) {
                continue;
            }

            try {
                $disk->makeDirectory($path);
            } catch (Throwable $exception) {
                if (! $this->createCameraDiskDirectoryWithSmbClient($path)) {
                    throw $exception;
                }

                continue;
            }

            if (! $this->cameraDiskDirectoryExists($disk, $path) && ! $this->createCameraDiskDirectoryWithSmbClient($path)) {
                throw new RuntimeException('Unable to create the camera storage directory on the active network share: '.$path);
            }
        }
    }

    private function cameraDiskDirectoryExists(FilesystemContract $disk, string $path): bool
    {
        try {
            if (method_exists($disk, 'directoryExists')) {
                return $disk->directoryExists($path);
            }

            return $disk->files($path) !== [] || $disk->directories($path) !== [];
        } catch (Throwable) {
            return false;
        }
    }

    private function createCameraDiskDirectoryWithSmbClient(string $path): bool
    {
        $networkConfig = $this->settings->networkStorageDiskConfig();

        if (! is_array($networkConfig)) {
            return false;
        }

        $binary = $this->smbClientBinary();

        if ($binary === null) {
            return false;
        }

        $targetPaths = $this->cameraDiskSmbDirectoryTargetPaths($path);

        if ($targetPaths === []) {
            return false;
        }

        foreach ($targetPaths as $targetPath) {
            $process = $this->runSmbClientCommand(
                $binary,
                $networkConfig,
                'mkdir "'.$targetPath.'"',
                30,
            );

            if ($process->isSuccessful()) {
                continue;
            }

            $errorOutput = trim($process->getErrorOutput().' '.$process->getOutput());

            if (
                str_contains($errorOutput, 'NT_STATUS_OBJECT_NAME_COLLISION')
                || str_contains($errorOutput, 'NT_STATUS_OBJECT_NAME_EXISTS')
            ) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function cameraDiskSmbDirectoryTargetPaths(string $path): array
    {
        $networkConfig = $this->settings->networkStorageDiskConfig();

        if (! is_array($networkConfig)) {
            return [];
        }

        $segments = array_filter(explode('/', trim(str_replace('\\', '/', (string) ($networkConfig['root'] ?? '')), '/')));
        $segments = array_merge($segments, array_filter(explode('/', trim(str_replace('\\', '/', $path), '/'))));

        if (collect($segments)->contains(fn (string $segment): bool => ! $this->isSafeSmbClientPath($segment))) {
            return [];
        }

        $targets = [];
        $current = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $current = $current === '' ? $segment : $current.'/'.$segment;
            $targets[] = $current;
        }

        return array_values(array_unique($targets));
    }

    protected function cameraDiskFileAvailabilityWithSmbClient(string $diskPath): ?string
    {
        $metadata = $this->cameraDiskFileMetadataWithSmbClient($diskPath);
        $availability = $metadata['availability'] ?? null;

        return is_string($availability) ? $availability : null;
    }

    protected function cameraDiskFileSizeWithSmbClient(string $diskPath): ?int
    {
        $metadata = $this->cameraDiskFileMetadataWithSmbClient($diskPath);

        if (($metadata['availability'] ?? null) !== self::RECORDING_AVAILABILITY_PRESENT) {
            return null;
        }

        $size = $metadata['size'] ?? null;

        return is_int($size) ? $size : null;
    }

    /**
     * @return bool|null True when deleted or already missing, false on a confirmed CLI failure, null when unavailable.
     */
    protected function deleteCameraDiskPathWithSmbClient(string $diskPath): ?bool
    {
        if (! $this->shouldUseSmbClientTransfers()) {
            return null;
        }

        $networkConfig = $this->settings->networkStorageDiskConfig();
        $binary = $this->smbClientBinary();
        $targetPaths = $this->cameraDiskReadSmbTargetPaths($diskPath);

        if (! is_array($networkConfig) || $binary === null || $targetPaths === []) {
            return null;
        }

        $deletedOrMissing = false;

        foreach ($targetPaths as $targetPath) {
            try {
                $process = $this->runSmbClientCommand(
                    $binary,
                    $networkConfig,
                    'del "'.$targetPath.'"',
                    30,
                );
            } catch (Throwable) {
                return false;
            }

            if ($process->isSuccessful()) {
                $deletedOrMissing = true;

                continue;
            }

            $errorOutput = strtolower(trim($process->getErrorOutput().' '.$process->getOutput()));

            if (collect([
                'nt_status_object_name_not_found',
                'nt_status_no_such_file',
                'nt_status_object_path_not_found',
                'not found',
            ])->contains(static fn (string $needle): bool => str_contains($errorOutput, $needle))) {
                $deletedOrMissing = true;

                continue;
            }

            return false;
        }

        return $deletedOrMissing;
    }

    /**
     * @return array{availability: string, size: int|null}|null
     */
    protected function cameraDiskFileMetadataWithSmbClient(string $diskPath): ?array
    {
        $networkConfig = $this->settings->networkStorageDiskConfig();

        if (! is_array($networkConfig)) {
            return null;
        }

        $binary = $this->smbClientBinary();

        if ($binary === null) {
            return null;
        }

        $targetPaths = $this->cameraDiskReadSmbTargetPaths($diskPath);

        if ($targetPaths === []) {
            return null;
        }

        $encounteredMissing = false;

        foreach ($targetPaths as $targetPath) {
            try {
                $process = $this->runSmbClientCommand(
                    $binary,
                    $networkConfig,
                    'allinfo "'.$targetPath.'"',
                    self::SMB_METADATA_TIMEOUT_SECONDS,
                );
            } catch (Throwable) {
                return null;
            }

            $combinedOutput = trim($process->getErrorOutput().' '.$process->getOutput());
            $errorOutput = strtolower($combinedOutput);

            foreach (['nt_status_object_name_not_found', 'nt_status_no_such_file', 'nt_status_object_path_not_found', 'not found'] as $needle) {
                if (str_contains($errorOutput, $needle)) {
                    $encounteredMissing = true;

                    continue 2;
                }
            }

            if ($process->isSuccessful()) {
                return [
                    'availability' => self::RECORDING_AVAILABILITY_PRESENT,
                    'size' => $this->parseSmbClientAllInfoSize($combinedOutput),
                ];
            }

            return null;
        }

        return $encounteredMissing
            ? [
                'availability' => self::RECORDING_AVAILABILITY_MISSING,
                'size' => null,
            ]
            : null;
    }

    private function parseSmbClientAllInfoSize(string $output): ?int
    {
        foreach ([
            '/(?:^|\R)\s*size:\s*(\d+)\b/im',
            '/(?:^|\R)\s*eof:\s*(\d+)\b/im',
            '/(?:^|\R)\s*end of file:\s*(\d+)\b/im',
            '/(?:^|\R)\s*stream:\s*\[::\$DATA\],\s*(\d+)\s+bytes\b/im',
        ] as $pattern) {
            if (preg_match($pattern, $output, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    /**
     * @param  array{host: string, share: string, root: string, username: string, password: string}  $networkConfig
     */
    private function smbClientTargetFileSize(string $binary, array $networkConfig, string $targetPath): ?int
    {
        try {
            $process = $this->runSmbClientCommand(
                $binary,
                $networkConfig,
                'allinfo "'.$targetPath.'"',
                self::SMB_METADATA_TIMEOUT_SECONDS,
            );

            return $process->isSuccessful()
                ? $this->parseSmbClientAllInfoSize($process->getOutput())
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isSafeSmbClientPath(string $path): bool
    {
        return $path !== '' && preg_match('/[";\x00-\x1F\x7F]/', $path) !== 1;
    }

    private function imageMimeTypeFromAbsolutePath(string $absolutePath): ?string
    {
        $imageInfo = @getimagesize($absolutePath);

        if (! is_array($imageInfo) || ! is_string($imageInfo['mime'] ?? null)) {
            return null;
        }

        return $imageInfo['mime'];
    }

    private function shouldUseSmbClientTransfers(): bool
    {
        return $this->usingNetworkStorage()
            && (string) config('filesystems.disks.camera_private.driver', 'local') === 'smb'
            && is_array($this->settings->networkStorageDiskConfig())
            && $this->smbClientBinary() !== null;
    }

    private function smbClientBinary(): ?string
    {
        return $this->resolveBinary(['smbclient']);
    }

    /**
     * @param  array{host: string, share: string, root: string, username: string, password: string}  $networkConfig
     */
    private function runSmbClientCommand(string $binary, array $networkConfig, string $command, int $timeoutSeconds): Process
    {
        $authFile = $this->createSmbAuthenticationFile($networkConfig);

        try {
            $process = new Process([
                $binary,
                '//'.$networkConfig['host'].'/'.$networkConfig['share'],
                '-A',
                $authFile,
                '-c',
                $command,
            ]);
            $process->setTimeout(max(1, $timeoutSeconds));
            $process->run();

            return $process;
        } finally {
            if (is_file($authFile)) {
                @unlink($authFile);
            }
        }
    }

    /**
     * @param  array{host: string, share: string, root: string, username: string, password: string}  $networkConfig
     */
    private function createSmbAuthenticationFile(array $networkConfig): string
    {
        $directory = storage_path('app/private/'.self::SMB_AUTH_DIRECTORY);
        $this->ensureWritableDirectory($directory);
        $authFile = tempnam($directory, 'smb-auth-');

        if (! is_string($authFile) || $authFile === '') {
            throw new RuntimeException('Unable to allocate a private SMB authentication file.');
        }

        [$domain, $username] = $this->parseSmbUsername($networkConfig['username']);
        $contents = 'username = '.$username.PHP_EOL
            .'password = '.$networkConfig['password'].PHP_EOL;

        if ($domain !== null) {
            $contents .= 'domain = '.$domain.PHP_EOL;
        }

        if (@file_put_contents($authFile, $contents, LOCK_EX) === false || ! @chmod($authFile, 0600)) {
            @unlink($authFile);

            throw new RuntimeException('Unable to create a private SMB authentication file.');
        }

        return $authFile;
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseSmbUsername(string $username): array
    {
        $trimmed = trim($username);

        if (preg_match('/^([^\\\\\/]+)[\\\\\/](.+)$/', $trimmed, $matches) === 1) {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [null, $trimmed];
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

    private function localReviewSpriteDirectoryRelativePath(string $recordingPath): ?string
    {
        $reviewDirectory = $this->reviewAssetDirectoryRelativePath($recordingPath);

        if ($reviewDirectory === null) {
            return null;
        }

        return 'review-sprites/'.$reviewDirectory;
    }

    private function privateStorageRelativePath(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
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

        if (! is_string($resolvedPath) || $resolvedPath === '') {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', $resolvedPath);

        if (! $this->pathWithinRoot($normalizedPath, $this->privateStorageRoot()) || ! is_file($normalizedPath)) {
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
        if (! is_string($path) || $path === '' || str_contains($path, "\0")) {
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
        if (! is_string($path) || trim($path) === '' || str_contains($path, "\0")) {
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
