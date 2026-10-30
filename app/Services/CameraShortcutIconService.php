<?php

namespace App\Services;

use App\Models\Camera;
use App\Services\Concerns\ResolvesConfiguredBinaries;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class CameraShortcutIconService
{
    use ResolvesConfiguredBinaries;

    public const SIZES = [180, 192, 512];

    public function __construct(private readonly CameraStorageService $storage) {}

    /**
     * @return array{revision: ?string, captured_at: ?string, apple_url: string, icons: array}
     */
    public function forCamera(Camera $camera): array
    {
        $fallback = [
            'revision' => null,
            'captured_at' => null,
            'apple_url' => asset('apple-touch-icon.png').'?v='.filemtime(public_path('apple-touch-icon.png')),
            'icons' => [
                ['src' => asset('img/bigbrotha-icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('img/bigbrotha-icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => asset('img/bigbrotha-icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];

        try {
            $preview = $camera->latestRtspPreview();
            if ($preview === null) {
                return $fallback;
            }

            $profile = $preview['profile'];
            $capturedAt = $profile['preview_generated_at'] ?? $profile['probe_checked_at'] ?? null;
            $revision = hash('sha256', json_encode([
                'v1', $camera->getKey(), $preview['index'], $profile['preview_path'], $capturedAt,
            ], JSON_THROW_ON_ERROR));

            if (! $this->prepare($camera, $revision, $profile['preview_path'])) {
                return $fallback;
            }

            $url = fn (int $size): string => route('cameras.shortcut-icon', [
                'camera' => $camera, 'revision' => $revision, 'size' => $size,
            ]);

            return [
                'revision' => $revision,
                'captured_at' => $capturedAt,
                'apple_url' => $url(180),
                'icons' => array_map(fn (int $size): array => [
                    'src' => $url($size), 'sizes' => $size.'x'.$size, 'type' => 'image/png', 'purpose' => 'any',
                ], [192, 512]),
            ];
        } catch (Throwable) {
            return $fallback;
        }
    }

    public function iconPath(Camera $camera, string $revision, int $size): string
    {
        abort_unless(in_array($size, self::SIZES, true) && preg_match('/\A[a-f0-9]{64}\z/', $revision), 404);
        $path = $this->directory($camera).'/'.$revision.'/'.$size.'.png';

        return $this->validIcon($path, $size) ? $path : $this->fallbackPath($size);
    }

    private function prepare(Camera $camera, string $revision, string $previewPath): bool
    {
        $root = $this->directory($camera);
        File::ensureDirectoryExists($root, 0750, true);
        $directory = $root.'/'.$revision;
        if ($this->complete($directory)) {
            return true;
        }

        $lock = fopen($root.'/.lock', 'c');
        if ($lock === false) {
            return false;
        }

        $source = null;
        $temporary = null;
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return false;
            }
            if ($this->complete($directory)) {
                return true;
            }

            $binary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));
            if ($binary === null) {
                return false;
            }
            $source = $this->storage->resolvePreviewImage($previewPath);
            if ($source === null) {
                return false;
            }

            $temporary = $root.'/.build-'.Str::random(16);
            File::ensureDirectoryExists($temporary, 0750, true);
            $command = [
                $binary, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
                '-threads', '1', '-filter_complex_threads', '1',
                '-protocol_whitelist', 'file,pipe', '-i', $source['absolute_path'],
            ];
            $filters = ['[0:v]split=3[input180][input192][input512]'];
            foreach (self::SIZES as $size) {
                $inner = (int) ($size * 0.75);
                $filters[] = '[input'.$size.']scale='.$inner.':'.$inner.':force_original_aspect_ratio=decrease,'
                    .'setsar=1,pad='.$size.':'.$size.':(ow-iw)/2:(oh-ih)/2:color=0x10141b[icon'.$size.']';
            }
            array_push($command, '-filter_complex', implode(';', $filters));
            foreach (self::SIZES as $size) {
                array_push($command, '-map', '[icon'.$size.']', '-frames:v', '1', '-threads', '1', $temporary.'/'.$size.'.png');
            }
            $process = new Process($command);
            $process->setTimeout(5);
            $process->run();
            if (! $process->isSuccessful() || ! $this->complete($temporary)) {
                return false;
            }

            File::deleteDirectory($directory);
            if (! rename($temporary, $directory)) {
                return false;
            }
            $temporary = null;

            // These are disposable derivatives; keep the three newest snapshots.
            $revisions = array_filter(File::directories($root), fn (string $path): bool => $path !== $directory && preg_match('/\A[a-f0-9]{64}\z/', basename($path)) === 1);
            usort($revisions, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
            foreach (array_slice($revisions, 2) as $old) {
                File::deleteDirectory($old);
            }

            return true;
        } finally {
            if ($temporary !== null) {
                File::deleteDirectory($temporary);
            }
            $this->storage->deleteTemporaryFile($source['absolute_path'] ?? null);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function directory(Camera $camera): string
    {
        return storage_path('app/private/cameras/'.$camera->getKey().'/shortcut-icons');
    }

    private function complete(string $directory): bool
    {
        foreach (self::SIZES as $size) {
            if (! $this->validIcon($directory.'/'.$size.'.png', $size)) {
                return false;
            }
        }

        return true;
    }

    private function validIcon(string $path, int $size): bool
    {
        $image = is_file($path) ? @getimagesize($path) : false;

        return is_array($image) && $image[0] === $size && $image[1] === $size && $image[2] === IMAGETYPE_PNG;
    }

    private function fallbackPath(int $size): string
    {
        return public_path($size === 180 ? 'apple-touch-icon.png' : 'img/bigbrotha-icon-'.$size.'.png');
    }
}
