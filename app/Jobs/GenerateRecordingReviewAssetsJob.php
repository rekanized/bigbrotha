<?php

namespace App\Jobs;

use App\Models\CameraRecording;
use App\Services\RecordingReviewAssetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class GenerateRecordingReviewAssetsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $recordingId)
    {
        $this->timeout = max(180, (int) config('recording.review_assets.job_timeout_seconds', 240));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(RecordingReviewAssetService $reviewAssets): void
    {
        $recording = CameraRecording::query()->find($this->recordingId);

        if (!$recording instanceof CameraRecording || $recording->status !== CameraRecording::STATUS_RECORDED || $recording->relative_path === null) {
            return;
        }

        $lock = Cache::lock('camera-recordings:review-assets:'.$recording->getKey(), $reviewAssets->lockSeconds());

        if (!$lock->get()) {
            if ($this->attempts() >= $this->tries) {
                $reviewAssets->recordJobFailure($recording, 'The review asset lock stayed busy through all retry attempts.');

                throw new RuntimeException('The recording review asset lock is still busy after the retry limit.');
            }

            $this->release(10);

            return;
        }

        try {
            $reviewAssets->generateForRecording($recording);
        } catch (Throwable $exception) {
            if ($this->attempts() >= $this->tries) {
                $reviewAssets->recordJobFailure($recording, 'Final review asset attempt failed: '.$this->summarizeThrowable($exception).'.');
            }

            throw $exception;
        } finally {
            $lock->release();

            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }

            if (function_exists('gc_mem_caches')) {
                gc_mem_caches();
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $recording = CameraRecording::query()->find($this->recordingId);

        if (!$recording instanceof CameraRecording || $recording->status !== CameraRecording::STATUS_RECORDED || $recording->relative_path === null) {
            return;
        }

        app(RecordingReviewAssetService::class)->recordJobFailure(
            $recording,
            'The queue worker marked the review asset job as failed after '.$this->tries.' attempts: '.$this->summarizeThrowable($exception).'.',
        );
    }

    private function summarizeThrowable(?Throwable $exception): string
    {
        if ($exception === null) {
            return 'Unknown queue worker failure';
        }

        $message = trim($exception->getMessage());

        if ($message === '') {
            $message = class_basename($exception);
        }

        return str($message)->squish()->limit(240)->value();
    }
}