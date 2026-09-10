<?php

namespace App\Services;

use App\Models\Camera;
use Illuminate\Support\Facades\Cache;

class MotionEditorSampleService
{
    public function __construct(
        private readonly RecordingMotionDetectorService $detector,
        private readonly MotionEditorStreamService $stream,
    ) {}

    public function waitingMessage(Camera $camera): string
    {
        return $this->stream->waitingMessage($camera);
    }

    /** Drafts share decoding; comparison spacing remains identical to the recorder. */
    public function sample(Camera $camera): ?array
    {
        $window = $this->stream->frames($camera);
        $stride = $this->stream->frameStride();
        if ($window === null || count($window['frames']) < (2 * $stride) + 1) {
            return null;
        }

        $frames = [];
        for ($index = count($window['frames']) - 1; $index >= 0; $index -= $stride) {
            $frames[] = $window['frames'][$index];
        }
        $confirmedIndex = count($window['frames']) - 1 - $stride;
        $observedAt = $window['receivedAt'][$confirmedIndex];
        if ((now()->getTimestampMs() / 1000) - $observedAt > 3) {
            return null;
        }
        $sampleId = $window['generation'].':'.($window['sequence'] - $stride);
        $settings = hash('sha256', serialize([$camera->recordingMotionMask(), $camera->motionTriggerPixels(), config('recording.motion')]));
        $cache = Cache::store(config('recording.motion.editor_cache_store', 'file'));
        $key = 'motion-editor:v3:'.$camera->getKey();
        $cached = $cache->get($key);
        if (is_array($cached) && $cached['sample_id'] === $sampleId && $cached['settings'] === $settings) {
            return $cached;
        }

        $lock = $cache->lock($key.':lock', 10);
        if (! $lock->get()) {
            return null;
        }
        try {
            $motion = $this->detector->analyzeFrames($camera, implode('', array_reverse($frames)), true, true);
            $sample = compact('motion', 'settings') + [
                'sample_id' => $sampleId,
                'observed_at' => $observedAt,
                'started_at' => $window['receivedAt'][0],
                'ended_at' => end($window['receivedAt']),
                'sample_fps' => $this->stream->frameRate(),
            ];
            $cache->put($key, $sample, 10);

            return $sample;
        } finally {
            $lock->release();
        }
    }
}
