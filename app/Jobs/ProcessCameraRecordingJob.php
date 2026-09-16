<?php

namespace App\Jobs;

use App\Models\CameraRecording;
use App\Services\CameraRecordingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ProcessCameraRecordingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $recordingId)
    {
        $this->timeout = max(
            (int) config('recording.job_timeout_seconds', 240),
            (int) config('recording.segment_seconds', 60) + (int) config('recording.motion.analysis_seconds', 5) + 90,
            (int) config('recording.motion.pre_roll_seconds', 8)
                + (int) config('recording.motion.analysis_seconds', 5)
                + (int) config('recording.motion.post_trigger_seconds', 20)
                + 120,
        );
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(CameraRecordingService $recordings): void
    {
        $recording = CameraRecording::query()->with('camera')->find($this->recordingId);

        if (!$recording instanceof CameraRecording || ! $recording->isPending()) {
            return;
        }

        $camera = $recording->camera;

        if ($camera === null) {
            $recordings->markRecordingFailed($recording, 'The camera record is no longer available to the queue worker.');

            return;
        }

        $lock = Cache::lock('camera-recordings:camera:'.$camera->getKey(), (int) config('recording.lock_seconds', 180));

        if (!$lock->get()) {
            if ($this->attempts() >= $this->tries) {
                $recordings->markRecordingFailed($recording, 'The per-camera recording lock stayed busy through all retry attempts.');

                throw new RuntimeException('The camera recording lock is still busy after the retry limit.');
            }

            $recordings->markRecordingQueued(
                $recording,
                'Waiting for the per-camera recording lock. Retry '.($this->attempts() + 1).' of '.$this->tries.' has been scheduled.',
            );

            $this->release(10);

            return;
        }

        try {
            $recording = $recording->fresh();

            if (! $recording instanceof CameraRecording || ! $recording->isPending()) {
                return;
            }

            $recordings->markRecordingProcessing(
                $recording,
                'Queue worker attempt '.$this->attempts().' of '.$this->tries.' is evaluating the recording segment.',
            );

            $recordings->processRecording($recording);
        } catch (Throwable $exception) {
            if ($this->attempts() < $this->tries) {
                $recordings->markRecordingQueued(
                    $recording,
                    'Attempt '.$this->attempts().' of '.$this->tries.' failed: '.$this->summarizeThrowable($exception).'. Laravel will retry.',
                );
            } else {
                $recordings->markRecordingFailed(
                    $recording,
                    'Final queue attempt failed: '.$this->summarizeThrowable($exception).'.',
                );
            }

            throw $exception;
        } finally {
            $lock->release();
            unset($recording, $camera, $lock);

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

        if (!$recording instanceof CameraRecording || !$recording->isPending()) {
            return;
        }

        app(CameraRecordingService::class)->markRecordingFailed(
            $recording,
            'The queue worker marked the recording job as failed after '.$this->tries.' attempts: '.$this->summarizeThrowable($exception).'.',
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
