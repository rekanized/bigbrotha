<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\CameraMotionState;
use App\Services\MotionEditorSampleService;
use App\Services\MotionRecordingSegmenterService;
use App\Services\RecordingMotionMaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CameraFleetMotionEditorAnalysisController extends Controller
{
    public function __invoke(
        Request $request,
        Camera $camera,
        MotionRecordingSegmenterService $segmenter,
        MotionEditorSampleService $samples,
        RecordingMotionMaskService $maskService,
    ): JsonResponse {
        abort_unless($camera->supports_rtsp, Response::HTTP_NOT_FOUND);

        $validated = $request->validate([
            'mask' => ['required', 'array'],
            'mask.version' => ['nullable', 'integer'],
            'mask.grid_width' => ['required', 'integer', 'min:1', 'max:640'],
            'mask.grid_height' => ['required', 'integer', 'min:1', 'max:640'],
            'mask.runs' => ['present', 'array', 'max:50000'],
            'mask.runs.*' => ['array', 'size:2'],
            'mask.runs.*.0' => ['integer', 'min:0'],
            'mask.runs.*.1' => ['integer', 'min:0'],
            'trigger_pixels' => ['required', 'integer', 'min:1'],
        ]);

        $savedMask = $camera->recordingMotionMask();
        $mask = $maskService->normalize($validated['mask']);

        abort_unless(
            $mask['grid_width'] === $savedMask['grid_width'] && $mask['grid_height'] === $savedMask['grid_height'],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'The motion mask grid does not match this camera.',
        );

        $selectedPixels = max(0, (int) $mask['selected_pixels']);
        $maximumTriggerPixels = max(1, $selectedPixels + ($selectedPixels * max(0, (int) config('recording.motion.cluster_bonus_multiplier', 2))));
        $triggerPixels = max(1, min($maximumTriggerPixels, (int) $validated['trigger_pixels']));
        $analysisCamera = $camera->replicate();
        $analysisCamera->forceFill([
            'id' => $camera->getKey(),
            'recording_motion_mask' => $mask,
            'recording_motion_trigger_pixels' => $triggerPixels,
        ]);
        $analysisCamera->exists = true;

        $settingsSaved = $mask === $savedMask
            && $triggerPixels === $camera->motionTriggerPixels($selectedPixels);
        $state = CameraMotionState::query()->where('camera_id', $camera->getKey())->first();

        if ($selectedPixels < 1) {
            return $this->waitingResponse('Paint an area to monitor movement.', 0, $triggerPixels, $settingsSaved, $state?->active_recording_id !== null);
        }

        if ($camera->recording_mode !== Camera::RECORDING_MODE_MOTION) {
            return $this->waitingResponse(
                'Save this camera in motion mode before testing the recorder decision.',
                $selectedPixels,
                $triggerPixels,
                false,
                false,
            );
        }

        if (! $camera->is_enabled) {
            return $this->waitingResponse(
                'Enable and save this camera before testing the recorder decision.',
                $selectedPixels,
                $triggerPixels,
                false,
                false,
            );
        }

        // Include the currently-written segment. A private snapshot gives ffmpeg a
        // stable file while keeping the editor on the recorder's canonical feed.
        $segments = $segmenter->closedSegmentsSince($camera, null, false);
        $segment = $segments === [] ? null : end($segments);

        if (! is_array($segment)) {
            return $this->waitingResponse(
                'Waiting for a segment from the rolling motion recorder.',
                $selectedPixels,
                $triggerPixels,
                $settingsSaved,
                $state?->active_recording_id !== null,
            );
        }

        $maximumSegmentAgeSeconds = max(10, ((int) config('recording.motion.segment_seconds', 4) * 3) + 5);

        if ($segment['ended_at']->lt(now()->utc()->subSeconds($maximumSegmentAgeSeconds))) {
            return $this->waitingResponse(
                'Waiting for a current segment from the rolling motion recorder.',
                $selectedPixels,
                $triggerPixels,
                $settingsSaved,
                $state?->active_recording_id !== null,
            );
        }

        clearstatcache(true, $segment['path']);
        $modifiedAt = @filemtime($segment['path']);

        if (is_int($modifiedAt) && now()->getTimestamp() - $modifiedAt > 3) {
            return $this->waitingResponse(
                'Waiting for fresh recorder frames. The buffer is not receiving data.',
                $selectedPixels,
                $triggerPixels,
                $settingsSaved,
                $state?->active_recording_id !== null,
            );
        }

        try {
            $sample = $samples->sample($analysisCamera, $segment['path']);

            if ($sample === null) {
                return $this->waitingResponse(
                    'Waiting for the next recorder sample.',
                    $selectedPixels,
                    $triggerPixels,
                    $settingsSaved,
                    $state?->active_recording_id !== null,
                );
            }

            $motion = $sample['motion'];
            $sampleId = $sample['sample_id'];

            if (((now()->getTimestampMs() / 1000) - $sample['observed_at']) > 3) {
                return $this->waitingResponse(
                    'Waiting for fresh recorder frames. The previous sample has expired.',
                    $selectedPixels,
                    $triggerPixels,
                    $settingsSaved,
                    $state?->active_recording_id !== null,
                );
            }

            if ((int) $motion['frame_count'] < 3) {
                return $this->waitingResponse(
                    'Waiting for the live recorder segment to contain confirmed frames.',
                    $selectedPixels,
                    $triggerPixels,
                    $settingsSaved,
                    $state?->active_recording_id !== null,
                );
            }
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'The recorder could not analyze the live motion segment.',
            ], Response::HTTP_SERVICE_UNAVAILABLE)->header('Cache-Control', 'private, no-store');
        }

        $activity = $motion['latest'] ?? [
            'detected' => (bool) $motion['detected'],
            'activity_ratio' => (float) $motion['activity_ratio'],
            'effective_trigger_pixels' => (int) $motion['changed_pixels'],
            'changed_indexes' => array_values(array_map('intval', $motion['changed_indexes'] ?? [])),
        ];
        $activity['moving_pixels'] = count($activity['changed_indexes']);
        $activity['mask_activity_ratio'] = round($activity['moving_pixels'] / $selectedPixels, 4);

        return response()->json([
            'status' => 'ready',
            'message' => match ($activity['reason'] ?? '') {
                'frame_artifact' => 'A camera-wide image change was filtered out.',
                'refresh_spike' => 'A brief image refresh was filtered out.',
                'noise' => 'Isolated pixel noise was filtered out.',
                default => $activity['detected']
                    ? 'Movement in the latest confirmed sample meets this threshold.'
                    : 'Movement in the latest confirmed sample is below this threshold.',
            },
            'settings_saved' => $settingsSaved,
            'recording_event_active' => $state?->active_recording_id !== null,
            'segment' => [
                'started_at' => $segment['started_at']->toIso8601String(),
                'ended_at' => $segment['ended_at']->toIso8601String(),
                'sampled_at' => Carbon::createFromTimestampUTC($sample['observed_at'])->toIso8601String(),
                'sample_age_ms' => max(0, (int) round(((now()->getTimestampMs() / 1000) - $sample['observed_at']) * 1000)),
                'live' => true,
                'sample_id' => $sampleId,
            ],
            'activity' => $activity,
            'decision' => [
                'detected' => (bool) $motion['detected'],
                'activity_ratio' => (float) $motion['activity_ratio'],
                'effective_trigger_pixels' => (int) $motion['changed_pixels'],
                'pixels_needed' => $triggerPixels,
                'selected_pixels' => (int) $motion['selected_pixels'],
                'frame_count' => (int) $motion['frame_count'],
                'changed_indexes' => array_values(array_map('intval', $motion['changed_indexes'] ?? [])),
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function waitingResponse(
        string $message,
        int $selectedPixels,
        int $triggerPixels,
        bool $settingsSaved,
        bool $recordingEventActive,
    ): JsonResponse {
        return response()->json([
            'status' => 'waiting',
            'message' => $message,
            'settings_saved' => $settingsSaved,
            'recording_event_active' => $recordingEventActive,
            'decision' => [
                'detected' => false,
                'activity_ratio' => 0.0,
                'effective_trigger_pixels' => 0,
                'pixels_needed' => $triggerPixels,
                'selected_pixels' => $selectedPixels,
                'frame_count' => 0,
                'changed_indexes' => [],
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
