<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class MotionEditorSampleService
{
    public function __construct(private readonly RecordingMotionDetectorService $detector) {}

    /**
     * One bounded cache entry and one non-blocking decode lock per camera.
     * Drafts never write camera settings or compete with the recorder's lock.
     */
    public function sample(Camera $camera, string $path): ?array
    {
        $cache = Cache::store(config('recording.motion.editor_cache_store', 'file'));
        $key = 'motion-editor:v2:'.$camera->getKey();
        $lock = $cache->lock($key.':lock', 60);

        if (! $lock->get()) {
            return null;
        }

        $snapshot = null;

        try {
            clearstatcache(true, $path);
            $stat = @stat($path);

            if ($stat === false || $stat['size'] < 1) {
                return null;
            }

            $fingerprint = hash('sha256', implode(':', [$path, $stat['ino'], $stat['size'], $stat['mtime'], $stat['ctime']]));
            $settings = hash('sha256', serialize([$camera->recordingMotionMask(), $camera->motionTriggerPixels(), config('recording.motion')]));
            $cached = $cache->get($key);

            $now = now()->getTimestampMs() / 1000;
            $minimumInterval = 1 / max(1, (int) config('recording.motion.analysis_fps', 3));

            if (is_array($cached) && $cached['settings'] === $settings
                && ($cached['fingerprint'] === $fingerprint
                    || ($cached['path'] === $path && $now - $cached['decoded_at'] < $minimumInterval))) {
                return $cached;
            }

            $directory = storage_path('app/private/ffmpeg-temp/motion-editor');
            File::ensureDirectoryExists($directory);
            $snapshot = $directory.'/camera-'.$camera->getKey().'-'.Str::uuid().'.mkv';
            $source = @fopen($path, 'rb');

            if ($source === false) {
                return null;
            }

            try {
                $target = @fopen($snapshot, 'wb');

                if ($target === false) {
                    throw new RuntimeException('Unable to create the motion preview snapshot.');
                }

                try {
                    // Do not chase a growing writer or decode a moving file.
                    $copied = stream_copy_to_stream($source, $target, $stat['size']);
                } finally {
                    fclose($target);
                }
            } finally {
                fclose($source);
            }

            if ($copied !== $stat['size']) {
                return null;
            }

            $motion = $this->detector->detectPreviewClip($camera, $snapshot);
            $sampleId = basename($path).':'.(int) $motion['frame_count'];
            // Audio growth, a different draft, and cache hits do not refresh video.
            $observedAt = is_array($cached) && $cached['sample_id'] === $sampleId
                ? $cached['observed_at']
                : now()->getTimestampMs() / 1000;
            $sample = compact('fingerprint', 'settings', 'motion', 'path') + [
                'decoded_at' => now()->getTimestampMs() / 1000,
                'sample_id' => $sampleId,
                'observed_at' => $observedAt,
            ];
            $cache->put($key, $sample, 60);

            return $sample;
        } finally {
            if ($snapshot !== null) {
                File::delete($snapshot);
            }

            $lock->release();
        }
    }
}
