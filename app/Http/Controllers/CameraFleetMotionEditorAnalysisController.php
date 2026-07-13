<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\CameraMotionState;
use App\Services\MotionRecordingSegmenterService;
use App\Services\RecordingMotionDetectorService;
use App\Services\RecordingMotionMaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CameraFleetMotionEditorAnalysisController extends Controller
{
    public function __invoke(
        Request $request,
        Camera $camera,
        MotionRecordingSegmenterService $segmenter,
        RecordingMotionDetectorService $detector,
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

        $segments = $segmenter->closedSegmentsSince($camera, null, true);
        $segment = $segments === [] ? null : end($segments);

        if (! is_array($segment)) {
            return $this->waitingResponse(
                'Waiting for a closed segment from the rolling motion recorder.',
                $selectedPixels,
                $triggerPixels,
                $settingsSaved,
                false,
            );
        }

        $maximumSegmentAgeSeconds = max(10, ((int) config('recording.motion.segment_seconds', 4) * 3) + 5);

        if ($segment['ended_at']->lt(now()->utc()->subSeconds($maximumSegmentAgeSeconds))) {
            return $this->waitingResponse(
                'Waiting for a current closed segment from the rolling motion recorder.',
                $selectedPixels,
                $triggerPixels,
                $settingsSaved,
                false,
            );
        }

        try {
            $motion = $detector->detectClip($analysisCamera, $segment['path']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'status' => 'error',
                'message' => 'The recorder could not analyze the latest closed motion segment.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'status' => 'ready',
            'message' => $motion['detected']
                ? 'The recorder detector qualifies motion in this segment.'
                : 'The recorder detector does not qualify motion in this segment.',
            'settings_saved' => $settingsSaved,
            'recording_event_active' => $state?->active_recording_id !== null,
            'segment' => [
                'started_at' => $segment['started_at']->toIso8601String(),
                'ended_at' => $segment['ended_at']->toIso8601String(),
            ],
            'decision' => [
                'detected' => (bool) $motion['detected'],
                'activity_ratio' => (float) $motion['activity_ratio'],
                'effective_trigger_pixels' => (int) $motion['changed_pixels'],
                'pixels_needed' => $triggerPixels,
                'selected_pixels' => (int) $motion['selected_pixels'],
                'frame_count' => (int) $motion['frame_count'],
                'changed_indexes' => array_values(array_map('intval', $motion['changed_indexes'] ?? [])),
            ],
        ]);
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
        ]);
    }
}
