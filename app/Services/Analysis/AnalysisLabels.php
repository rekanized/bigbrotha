<?php

namespace App\Services\Analysis;

use App\Models\Camera;
use App\Models\CameraAnalysisEvent;
use App\Models\CameraAnalysisLabel;
use App\Models\CameraRecording;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AnalysisLabels
{
    // Event class confirmation tolerates movement between sparse samples; this is not identity tracking.
    private const CONFIRMATION_IOU = 0.05;

    /** Confirm spatially consistent observations; thresholds come from the pinned model manifest. */
    public function confirm(array $detections, float $at, array &$previous): array
    {
        $detections = array_slice(array_values(array_filter($detections, $this->validDetection(...))), 0, 50);
        $previous = array_values(array_filter($previous, fn ($p) => $at - $p['at'] <= 3 && $at > $p['at']));
        $confirmed = [];
        foreach ($detections as $detection) {
            $times = [];
            $bestConfidence = $detection['confidence'];
            foreach ($previous as $prior) {
                if ($prior['class'] === $detection['class'] && $this->overlap($prior['box'], $detection['box']) >= self::CONFIRMATION_IOU) {
                    $times[] = $prior['at'];
                    $bestConfidence = max($bestConfidence, $prior['confidence']);
                }
            }
            if ($times !== [] || ($detection['high_confidence'] ?? false) === true) {
                $confirmed[] = [...$detection, 'confidence' => $bestConfidence, 'times' => [...$times, $at]];
            }
        }
        foreach ($detections as $detection) {
            $previous[] = [...$detection, 'at' => $at];
        }
        $previous = array_slice($previous, -150);

        return $confirmed;
    }

    public function validDetections(array $detections): bool
    {
        return array_is_list($detections) && count($detections) <= 50
            && count(array_filter($detections, $this->validDetection(...))) === count($detections);
    }

    private function validDetection(mixed $detection): bool
    {
        if (! is_array($detection) || ! in_array($detection['class'] ?? '', CameraAnalysisLabel::CLASSES, true)
            || ! is_array($detection['box'] ?? null) || ! array_is_list($detection['box']) || count($detection['box']) !== 4
            || ! is_numeric($detection['confidence'] ?? null)) {
            return false;
        }
        foreach ([...$detection['box'], $detection['confidence']] as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 1) {
                return false;
            }
        }

        return $detection['box'][2] > $detection['box'][0] && $detection['box'][3] > $detection['box'][1];
    }

    private function overlap(array $a, array $b): float
    {
        $intersection = max(0, min($a[2], $b[2]) - max($a[0], $b[0])) * max(0, min($a[3], $b[3]) - max($a[1], $b[1]));
        $union = max(0, $a[2] - $a[0]) * max(0, $a[3] - $a[1]) + max(0, $b[2] - $b[0]) * max(0, $b[3] - $b[1]) - $intersection;

        return $union > 0 ? $intersection / $union : 0;
    }

    public function record(CameraAnalysisEvent $event, array $confirmed): void
    {
        $classes = [];
        foreach ($confirmed as $detection) {
            $class = $detection['class'];
            $classes[$class] = ['class' => $class,
                'confidence' => max($classes[$class]['confidence'] ?? 0, $detection['confidence']),
                'times' => array_unique([...($classes[$class]['times'] ?? []), ...$detection['times']])];
        }
        if ($classes === []) {
            return;
        }
        // Serialize metadata updates with retention; inference has already finished.
        DB::transaction(function () use ($event, $classes): void {
            $current = CameraAnalysisEvent::query()->lockForUpdate()->find($event->id);
            if ($current === null) {
                return;
            }
            foreach ($classes as $detection) {
                $label = $current->labels()->lockForUpdate()->firstOrNew(['class' => $detection['class']]);
                $times = array_unique([...($label->observed_at ?? []), ...array_map(fn ($time) => Carbon::createFromTimestampUTC($time)->format('Y-m-d H:i:s.u'), $detection['times'])]);
                sort($times);
                $label->fill(['confidence' => max($label->confidence ?? 0, $detection['confidence']), 'observed_at' => array_values($times)])->save();
                $this->associate($label);
            }
        }, 3);
    }

    public function associate(CameraAnalysisLabel $label): void
    {
        DB::transaction(function () use ($label): void {
            // The coordinator and maintenance command can associate the same label concurrently.
            $current = CameraAnalysisLabel::query()->lockForUpdate()->find($label->id);
            if ($current === null || $current->observed_at === []) {
                return;
            }
            $times = array_map(fn ($time) => Carbon::parse($time, 'UTC'), $current->observed_at);
            $recordings = CameraRecording::query()->where('camera_id', $current->event->camera_id)
                ->where('status', CameraRecording::STATUS_RECORDED)->whereNotNull('started_at')->whereNotNull('ended_at')
                ->where('started_at', '<=', max($current->observed_at))->where('ended_at', '>', min($current->observed_at))
                ->orderBy('id')->sharedLock()->get();
            $rows = [];
            foreach ($recordings as $recording) {
                foreach ($times as $at) {
                    if ($at->greaterThanOrEqualTo($recording->started_at) && $at->lessThan($recording->ended_at)) {
                        $rows[] = ['label_id' => $current->id, 'recording_id' => $recording->id];
                        break;
                    }
                }
            }
            if ($rows !== []) {
                DB::table('camera_analysis_label_recording')->insertOrIgnore($rows);
                if (! $current->was_associated) {
                    $current->forceFill(['was_associated' => true])->save();
                }
            }
        }, 3);
    }

    public function maintain(): void
    {
        if (! CameraAnalysisEvent::query()->exists()) {
            return;
        }
        $cache = Cache::store('file');
        $scanStarted = now();
        $since = $cache->get('analysis:recording-cursor') ?? CameraAnalysisEvent::min('started_at') ?? $scanStarted;
        CameraRecording::query()->where('status', CameraRecording::STATUS_RECORDED)
            ->whereNotNull('started_at')->whereNotNull('ended_at')
            ->where('updated_at', '>=', Carbon::parse($since)->subSeconds(10))
            ->chunkById(100, function ($recordings): void {
                foreach ($recordings as $recording) {
                    CameraAnalysisLabel::query()->whereHas('event', fn ($q) => $q->where('camera_id', $recording->camera_id)
                        ->where('started_at', '<', $recording->ended_at->format('Y-m-d H:i:s.u'))
                        ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $recording->started_at->format('Y-m-d H:i:s.u'))))
                        ->with('event')->each(fn ($label) => $this->associate($label));
                }
            });
        $cache->forever('analysis:recording-cursor', $scanStarted->toISOString());
        Camera::query()->select(['id', 'recording_retention_days'])->each(function (Camera $camera): void {
            CameraAnalysisEvent::query()->where('camera_id', $camera->id)
                ->where('started_at', '<', now()->subDays(max(1, $camera->recording_retention_days)))->delete();
        });
        CameraAnalysisLabel::query()->where('was_associated', true)->whereDoesntHave('recordings')
            ->whereHas('event', fn ($q) => $q->whereNotNull('ended_at'))->delete();
        CameraAnalysisEvent::query()->whereNull('ended_at')->where('updated_at', '<', now()->subMinutes(5))
            ->update(['ended_at' => now()->format('Y-m-d H:i:s.u'), 'skipped_frames' => DB::raw('skipped_frames + 1')]);
    }
}
