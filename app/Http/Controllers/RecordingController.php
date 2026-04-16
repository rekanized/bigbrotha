<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\ApplicationSettingsService;
use App\Services\CameraRecordingService;
use App\Services\CameraStorageService;
use App\Services\RecordingReviewAssetService;
use App\Services\RecordingTimelineReviewService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordingController extends Controller
{
    public function __construct(
        private readonly ApplicationSettingsService $settings,
        private readonly RecordingTimelineReviewService $timelineReview,
    ) {
    }

    public function index(Request $request): View
    {
        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'camera_id' => $request->filled('camera_id') ? (int) $request->query('camera_id') : null,
            'status' => (string) $request->query('status', 'recorded'),
            'mode' => (string) $request->query('mode', 'all'),
            'date_from' => $this->validDateOrNull($request->query('date_from')),
            'date_to' => $this->validDateOrNull($request->query('date_to')),
        ];

        $recordings = CameraRecording::query()
            ->with('camera')
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function (Builder $query) use ($search): void {
                    $query->where('relative_path', 'like', '%'.$search.'%')
                        ->orWhere('message', 'like', '%'.$search.'%')
                        ->orWhereHas('camera', function (Builder $cameraQuery) use ($search): void {
                            $cameraQuery->where('name', 'like', '%'.$search.'%')
                                ->orWhere('local_ip', 'like', '%'.$search.'%')
                                ->orWhere('hostname', 'like', '%'.$search.'%');
                        });
                });
            })
            ->when($filters['camera_id'] !== null, function (Builder $query) use ($filters): void {
                $query->where('camera_id', $filters['camera_id']);
            })
            ->when(in_array($filters['status'], CameraRecording::filterableStatuses(), true), function (Builder $query) use ($filters): void {
                $query->where('status', $filters['status']);
            })
            ->when(in_array($filters['mode'], [Camera::RECORDING_MODE_CONTINUOUS, Camera::RECORDING_MODE_MOTION], true), function (Builder $query) use ($filters): void {
                $query->where('capture_mode', $filters['mode']);
            })
            ->when($filters['date_from'] !== null, function (Builder $query) use ($filters): void {
                $query->where('scheduled_for', '>=', $this->settings->startOfDisplayDayUtc($filters['date_from']));
            })
            ->when($filters['date_to'] !== null, function (Builder $query) use ($filters): void {
                $query->where('scheduled_for', '<', $this->settings->startOfDisplayDayUtc($filters['date_to'])->addDay());
            })
            ->orderByDesc('scheduled_for')
            ->paginate(18)
            ->withQueryString();

        $summaryQuery = CameraRecording::query();

        $summary = [
            'total' => (clone $summaryQuery)->count(),
            'recorded' => (clone $summaryQuery)->where('status', CameraRecording::STATUS_RECORDED)->count(),
            'motion' => (clone $summaryQuery)
                ->where('capture_mode', Camera::RECORDING_MODE_MOTION)
                ->where('status', CameraRecording::STATUS_RECORDED)
                ->count(),
            'failed' => (clone $summaryQuery)->where('status', CameraRecording::STATUS_FAILED)->count(),
        ];

        return view('recordings.index', [
            'recordings' => $recordings,
            'filters' => $filters,
            'summary' => $summary,
            'displayTimezone' => $this->settings->appTimezone(),
            'cameraOptions' => $this->cameraOptions(),
            'statusOptions' => [
                'all' => 'All statuses',
                CameraRecording::STATUS_RECORDED => 'Recorded only',
                CameraRecording::STATUS_QUEUED => 'Queued',
                CameraRecording::STATUS_PROCESSING => 'Processing',
                CameraRecording::STATUS_SKIPPED => 'Skipped',
                CameraRecording::STATUS_FAILED => 'Failed',
            ],
            'modeOptions' => [
                'all' => 'All capture modes',
                Camera::RECORDING_MODE_CONTINUOUS => 'Constantly recording',
                Camera::RECORDING_MODE_MOTION => 'Record on movement',
            ],
        ]);
    }

    public function timeline(Request $request): View
    {
        return view('recordings.timeline', $this->timelineViewData($request));
    }

    public function timelineRailData(Request $request, Camera $camera): JsonResponse
    {
        abort_unless(
            $camera->recordings()
                ->where('status', CameraRecording::STATUS_RECORDED)
                ->whereNotNull('relative_path')
                ->exists(),
            Response::HTTP_NOT_FOUND,
        );

        $dayStartMs = $this->requestMilliseconds($request->query('day_start_ms'));
        $dayEndMs = $this->requestMilliseconds($request->query('day_end_ms'));

        abort_unless($dayStartMs !== null && $dayEndMs !== null && $dayEndMs > ($dayStartMs + 999), Response::HTTP_UNPROCESSABLE_ENTITY);

        $windowStartMs = $this->requestMilliseconds($request->query('window_start_ms')) ?? $dayStartMs;
        $windowEndMs = $this->requestMilliseconds($request->query('window_end_ms')) ?? $dayEndMs;
        [$normalizedStartMs, $normalizedEndMs] = $this->normalizeTimelineWindowRange($dayStartMs, $dayEndMs, $windowStartMs, $windowEndMs);
        $reviewWindowStart = $this->millisecondsToUtc($dayStartMs);
        $reviewWindowEnd = $this->millisecondsToUtc($dayEndMs);
        $windowStart = $this->millisecondsToUtc($normalizedStartMs);
        $windowEnd = $this->millisecondsToUtc($normalizedEndMs);

        $segments = $this->reviewRecordings(collect([(int) $camera->getKey()]), $windowStart, $windowEnd)
            ->map(fn (CameraRecording $recording): array => $this->timelineReview->recordingReviewPayload($recording, $reviewWindowStart, $reviewWindowEnd))
            ->values()
            ->all();

        return response()->json([
            'cameraId' => (int) $camera->getKey(),
            'segments' => $segments,
            'windowEndMs' => $normalizedEndMs,
            'windowStartMs' => $normalizedStartMs,
        ]);
    }

    public function timelineStageData(Request $request, Camera $camera): JsonResponse
    {
        abort_unless(
            $camera->recordings()
                ->where('status', CameraRecording::STATUS_RECORDED)
                ->whereNotNull('relative_path')
                ->exists(),
            Response::HTTP_NOT_FOUND,
        );

        $dayStartMs = $this->requestMilliseconds($request->query('day_start_ms'));
        $dayEndMs = $this->requestMilliseconds($request->query('day_end_ms'));
        $focusMs = $this->requestMilliseconds($request->query('focus_ms'));

        abort_unless(
            $dayStartMs !== null
                && $dayEndMs !== null
                && $focusMs !== null
                && $dayEndMs > ($dayStartMs + 999),
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );

        $maximumFocusMs = max($dayStartMs, $dayEndMs - 1000);
        $normalizedFocusMs = max($dayStartMs, min($maximumFocusMs, $focusMs));
        $reviewWindowStart = $this->millisecondsToUtc($dayStartMs);
        $reviewWindowEnd = $this->millisecondsToUtc($dayEndMs);
        $focusAt = $this->millisecondsToUtc($normalizedFocusMs);

        return response()->json([
            'cameraId' => (int) $camera->getKey(),
            'focusMs' => $normalizedFocusMs,
            'segment' => $this->timelineStageSegmentPayload($camera, $focusAt, $reviewWindowStart, $reviewWindowEnd),
        ]);
    }

    public function show(CameraRecording $recording, CameraStorageService $storage, CameraRecordingService $recordings): View
    {
        $recording->loadMissing('camera');
        $durationSeconds = $this->durationSeconds($recording);
        $playbackAvailable = $recording->status === CameraRecording::STATUS_RECORDED && $storage->recordingExists($recording->relative_path);
        $playbackAssetReady = app(RecordingReviewAssetService::class)->hasReadyPlaybackAsset($recording);

        return view('recordings.show', [
            'recording' => $recording,
            'camera' => $recording->camera,
            'durationSeconds' => $durationSeconds,
            'displayTimezone' => $this->settings->appTimezone(),
            'playbackAvailable' => $playbackAvailable,
            'browserPlaybackAvailable' => $playbackAvailable && ($playbackAssetReady || $recordings->ffmpegBinary() !== null),
        ]);
    }

    public function stream(CameraRecording $recording, CameraStorageService $storage, CameraRecordingService $recordings): Response|BinaryFileResponse|StreamedResponse
    {
        abort_unless($recording->status === CameraRecording::STATUS_RECORDED, Response::HTTP_NOT_FOUND);
        abort_unless($storage->recordingExists($recording->relative_path), Response::HTTP_NOT_FOUND);

        return $recordings->playbackResponse($recording->loadMissing('camera'));
    }

    public function reviewStream(Request $request, CameraRecording $recording, CameraStorageService $storage, CameraRecordingService $recordings): Response
    {
        abort_unless($recording->status === CameraRecording::STATUS_RECORDED, Response::HTTP_NOT_FOUND);
        abort_unless($storage->recordingExists($recording->relative_path), Response::HTTP_NOT_FOUND);

        return $recordings->bufferedPlaybackResponse($recording->loadMissing('camera'), $request->header('Range'));
    }

    public function previewThumbnail(CameraRecording $recording, RecordingReviewAssetService $reviewAssets): Response|BinaryFileResponse
    {
        abort_unless($recording->status === CameraRecording::STATUS_RECORDED, Response::HTTP_NOT_FOUND);

        $thumbnailSvg = $reviewAssets->thumbnailSvg($recording);

        if ($thumbnailSvg !== null) {
            return response($thumbnailSvg, Response::HTTP_OK, [
                'Content-Type' => 'image/svg+xml; charset=UTF-8',
                'Cache-Control' => 'private, max-age=300',
            ]);
        }

        $reviewAssets->ensureQueued($recording, true);

        return response($this->previewThumbnailPlaceholder($recording), Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function previewSprite(CameraRecording $recording, RecordingReviewAssetService $reviewAssets): Response|BinaryFileResponse
    {
        abort_unless($recording->status === CameraRecording::STATUS_RECORDED, Response::HTTP_NOT_FOUND);

        $absolutePath = $reviewAssets->scrubSpriteAbsolutePath($recording);

        if ($absolutePath === null || !is_file($absolutePath)) {
            $reviewAssets->ensureQueued($recording, true);
            $absolutePath = null;
        }

        if ($absolutePath !== null && is_file($absolutePath)) {
            $response = response()->file($absolutePath, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, max-age=300',
            ]);

            $response->deleteFileAfterSend(app(CameraStorageService::class)->isTemporaryManagedPath($absolutePath));

            return $response;
        }

        return response($this->previewThumbnailPlaceholder($recording), Response::HTTP_OK, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    public function download(CameraRecording $recording, CameraStorageService $storage): BinaryFileResponse
    {
        $absolutePath = $storage->resolveRecordingAbsolutePath($recording->relative_path);

        abort_unless($recording->status === CameraRecording::STATUS_RECORDED && $absolutePath !== null, Response::HTTP_NOT_FOUND);

        $extension = pathinfo((string) $recording->relative_path, PATHINFO_EXTENSION) ?: (pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'mkv');
        $fileName = Str::slug($recording->camera?->name ?: 'camera-recording').'-'.($recording->scheduled_for?->format('Ymd_His') ?? 'segment').'.'.$extension;

        $response = response()->download($absolutePath, $fileName, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);

        $response->deleteFileAfterSend($storage->isTemporaryManagedPath($absolutePath));

        return $response;
    }

    /**
     * @return Collection<int, Camera>
     */
    private function cameraOptions(): Collection
    {
        return Camera::query()
            ->whereHas('recordings', function (Builder $query): void {
                $query
                    ->where('status', CameraRecording::STATUS_RECORDED)
                    ->whereNotNull('relative_path');
            })
            ->orderBy('name')
            ->get(['id', 'name', 'local_ip', 'metadata']);
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineViewData(Request $request): array
    {
        $requestedDateFrom = $this->validDateOrNull($request->query('date_from'));
        $requestedDateTo = $this->validDateOrNull($request->query('date_to'));
        $requestedDay = $this->validDateOrNull($request->query('day'));
        $requestedZoom = $this->validZoomScaleOrNull($request->query('zoom'));

        if ($requestedDateFrom === null && $requestedDateTo === null && $requestedDay !== null) {
            $requestedDateFrom = $requestedDay;
            $requestedDateTo = $requestedDay;
        }

        $timelineCameraOptions = $this->cameraOptions();
        $selectedCameraIds = $this->resolveTimelineCameraIds($request, $timelineCameraOptions);
        $selectedCameras = $timelineCameraOptions
            ->filter(fn (Camera $camera): bool => $selectedCameraIds->contains((int) $camera->getKey()))
            ->values();

        [$reviewWindowStart, $reviewWindowEnd] = $this->reviewTimelineBounds($selectedCameraIds, $requestedDateFrom, $requestedDateTo);
        $reviewRecordings = $this->reviewRecordings($selectedCameraIds, $reviewWindowStart, $reviewWindowEnd);
        $focusAt = $this->resolveFocusAt(
            $request->query('focus_at'),
            $reviewWindowStart,
            $reviewWindowEnd,
            $reviewRecordings,
        );
        $reviewTiles = $this->timelineReview->buildCameraSummaries($selectedCameras, $reviewRecordings, $focusAt, $reviewWindowStart, $reviewWindowEnd);
        $timelineHours = max(24, (int) ceil($this->timelineDurationHours($reviewWindowStart, $reviewWindowEnd)));
        $activeCameraId = $this->resolveActiveCameraId($reviewTiles);
        [$initialRailWindowStartMs, $initialRailWindowEndMs] = $this->initialRailWindow(
            $reviewWindowStart->valueOf(),
            $reviewWindowEnd->valueOf(),
            $focusAt->valueOf(),
        );
        $summaryQuery = CameraRecording::query()
            ->when($selectedCameraIds->isNotEmpty(), function (Builder $query) use ($selectedCameraIds): void {
                $query->whereIn('camera_id', $selectedCameraIds->all());
            });

        $this->applyReviewWindow($summaryQuery, $reviewWindowStart, $reviewWindowEnd);

        return [
            'summary' => [
                'total' => (clone $summaryQuery)->count(),
                'recorded' => (clone $summaryQuery)->where('status', CameraRecording::STATUS_RECORDED)->count(),
                'motion' => (clone $summaryQuery)
                    ->where('capture_mode', Camera::RECORDING_MODE_MOTION)
                    ->where('status', CameraRecording::STATUS_RECORDED)
                    ->count(),
                'failed' => (clone $summaryQuery)->where('status', CameraRecording::STATUS_FAILED)->count(),
                'cameras' => $reviewTiles->count(),
            ],
            'timelineCameraOptions' => $timelineCameraOptions,
            'timelineTicks' => $this->timelineTicks($reviewWindowStart, $reviewWindowEnd)->values()->all(),
            'reviewRangeLabel' => $this->settings->formatDateTime($reviewWindowStart, 'Y-m-d H:i')
                .' - '.$this->settings->formatDateTime($reviewWindowEnd->copy()->subSecond(), 'Y-m-d H:i'),
            'reviewTiles' => $reviewTiles,
            'initialCurrentSegment' => $this->initialCurrentSegment(
                $reviewRecordings,
                $activeCameraId,
                $focusAt,
                $reviewWindowStart,
                $reviewWindowEnd,
            ),
            'initialRailSegments' => $this->initialRailSegments(
                $reviewRecordings,
                $activeCameraId,
                $reviewWindowStart,
                $reviewWindowEnd,
                $initialRailWindowStartMs,
                $initialRailWindowEndMs,
            ),
            'initialRailWindowEndMs' => $initialRailWindowEndMs,
            'initialRailWindowStartMs' => $initialRailWindowStartMs,
            'selectedCameraIds' => $selectedCameraIds->all(),
            'dateRange' => [
                'from' => $this->settings->toDisplayTimezone($reviewWindowStart)->format('Y-m-d'),
                'to' => $this->settings->toDisplayTimezone($reviewWindowEnd->copy()->subSecond())->format('Y-m-d'),
            ],
            'timelineHours' => $timelineHours,
            'timelinePayload' => [
                'activeCameraId' => $activeCameraId,
                'dayStartMs' => $reviewWindowStart->valueOf(),
                'dayEndMs' => $reviewWindowEnd->valueOf(),
                'focusAtMs' => $focusAt->valueOf(),
                'displayTimezone' => $this->settings->javascriptTimezone(),
                'zoomScale' => $requestedZoom,
                'timelineHours' => $timelineHours,
            ],
        ];
    }

    /**
     * @param  Collection<int, Camera>  $cameraOptions
     * @return Collection<int, int>
     */
    private function resolveTimelineCameraIds(Request $request, Collection $cameraOptions): Collection
    {
        $allowedIds = $cameraOptions
            ->map(fn (Camera $camera): int => (int) $camera->getKey())
            ->values();

        $requestedIds = collect($request->query('camera_ids', []));

        if ($requestedIds->count() === 1 && is_string($requestedIds->first()) && str_contains((string) $requestedIds->first(), ',')) {
            $requestedIds = collect(explode(',', (string) $requestedIds->first()));
        }

        $selectedIds = $requestedIds
            ->map(fn (mixed $cameraId): int => (int) $cameraId)
            ->filter(fn (int $cameraId): bool => $allowedIds->contains($cameraId))
            ->unique()
            ->values();

        if ($selectedIds->isNotEmpty()) {
            return $selectedIds;
        }

        return $allowedIds->values();
    }

    /**
     * @param  Collection<int, int>  $selectedCameraIds
     * @return array{0: Carbon, 1: Carbon}
     */
    private function reviewTimelineBounds(Collection $selectedCameraIds, ?string $requestedDateFrom, ?string $requestedDateTo): array
    {
        [$normalizedDateFrom, $normalizedDateTo] = $this->normalizeRequestedReviewDateRange($requestedDateFrom, $requestedDateTo);

        if ($normalizedDateFrom !== null && $normalizedDateTo !== null) {
            $rangeStart = $this->settings->startOfDisplayDayUtc($normalizedDateFrom);
            $rangeEnd = $this->settings->startOfDisplayDayUtc($normalizedDateTo)->addDay();

            return [$rangeStart, $rangeEnd];
        }

        [$defaultRangeStart, $defaultRangeEnd] = $this->defaultTimelineReviewBounds();

        return [$defaultRangeStart, $defaultRangeEnd];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function normalizeRequestedReviewDateRange(?string $requestedDateFrom, ?string $requestedDateTo): array
    {
        if ($requestedDateFrom === null && $requestedDateTo === null) {
            return [null, null];
        }

        $normalizedDateFrom = $requestedDateFrom ?? $requestedDateTo;
        $normalizedDateTo = $requestedDateTo ?? $requestedDateFrom;

        if ($normalizedDateFrom === null || $normalizedDateTo === null) {
            return [null, null];
        }

        if ($normalizedDateFrom > $normalizedDateTo) {
            [$normalizedDateFrom, $normalizedDateTo] = [$normalizedDateTo, $normalizedDateFrom];
        }

        return [$normalizedDateFrom, $normalizedDateTo];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function defaultTimelineReviewBounds(): array
    {
        $currentDisplayDayStart = now()->setTimezone($this->settings->appTimezone())->startOfDay()->utc();
        $rangeStart = $currentDisplayDayStart->copy()->subDay();

        return [$rangeStart, $rangeStart->copy()->addDays(2)];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function timelineTicks(Carbon $reviewWindowStart, Carbon $reviewWindowEnd): Collection
    {
        $ticks = collect();
        $cursor = $reviewWindowStart->copy();
        $totalHours = max(1, $this->timelineDurationHours($reviewWindowStart, $reviewWindowEnd));
        $tickIntervalMinutes = 15;

        while ($cursor->lessThan($reviewWindowEnd)) {
            $nextCursor = $cursor->copy()->addMinutes($tickIntervalMinutes);

            if ($nextCursor->greaterThan($reviewWindowEnd)) {
                $nextCursor = $reviewWindowEnd->copy();
            }

            $offsetHours = $this->timelineHourOffset($reviewWindowStart, $cursor);
            $heightHours = max(0, $this->timelineHourOffset($reviewWindowStart, $nextCursor) - $offsetHours);
            $localizedCursor = $this->settings->toDisplayTimezone($cursor);
            $isPrimary = $localizedCursor->minute === 0;
            $isDayStart = $isPrimary && $localizedCursor->format('H:i') === '00:00';
            $intervalMinutes = max(1, (int) round(($nextCursor->valueOf() - $cursor->valueOf()) / 60000));
            $labelVariant = $isDayStart ? 'day' : ($isPrimary ? 'hour' : 'minute');

            $ticks->push([
                'focusMs' => $cursor->valueOf(),
                'leftPercent' => round(($offsetHours / $totalHours) * 100, 6),
                'topHours' => round($offsetHours, 6),
                'topPercent' => round(($offsetHours / $totalHours) * 100, 6),
                'widthPercent' => round(($heightHours / $totalHours) * 100, 6),
                'heightHours' => round($heightHours, 6),
                'heightPercent' => round(($heightHours / $totalHours) * 100, 6),
                'intervalMinutes' => $intervalMinutes,
                'isDayStart' => $isDayStart,
                'isPrimary' => $isPrimary,
                'kind' => $isPrimary ? 'primary' : 'secondary',
                'labelVariant' => $labelVariant,
                'labelPrimary' => $isDayStart
                    ? $localizedCursor->format('M')
                    : ($isPrimary ? $localizedCursor->format('H') : null),
                'labelSecondary' => $localizedCursor->format($isDayStart ? 'd' : 'i'),
                'label' => $isDayStart
                    ? $localizedCursor->format('M d')
                    : ($isPrimary ? $localizedCursor->format('H:i') : $localizedCursor->format('i')),
                'ariaLabel' => $localizedCursor->format('Y-m-d H:i'),
            ]);

            $cursor->addMinutes($tickIntervalMinutes);
        }

        return $ticks;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function initialRailWindow(int $dayStartMs, int $dayEndMs, int $focusAtMs): array
    {
        $chunkDurationMs = 28_800_000;
        $halfWindowMs = (int) floor($chunkDurationMs / 2);
        $windowStartMs = max($dayStartMs, $focusAtMs - $halfWindowMs);
        $windowEndMs = min($dayEndMs, $windowStartMs + $chunkDurationMs);

        if (($windowEndMs - $windowStartMs) < $chunkDurationMs) {
            $windowStartMs = max($dayStartMs, $windowEndMs - $chunkDurationMs);
        }

        return [$windowStartMs, max($windowStartMs + 1000, $windowEndMs)];
    }

    /**
     * @param  Collection<int, CameraRecording>  $reviewRecordings
     * @return array<string, mixed>|null
     */
    private function initialCurrentSegment(
        Collection $reviewRecordings,
        int|string|null $activeCameraId,
        Carbon $focusAt,
        Carbon $reviewWindowStart,
        Carbon $reviewWindowEnd,
    ): ?array {
        $cameraId = is_numeric($activeCameraId) ? (int) $activeCameraId : 0;

        if ($cameraId <= 0) {
            return null;
        }

        $cameraRecordings = $reviewRecordings
            ->filter(fn (mixed $recording): bool => $recording instanceof CameraRecording && (int) $recording->camera_id === $cameraId)
            ->values();

        $recording = $this->timelineReview->selectRecordingForFocus($cameraRecordings, $focusAt);

        return $recording instanceof CameraRecording
            ? $this->timelineReview->recordingReviewPayload($recording, $reviewWindowStart, $reviewWindowEnd)
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function timelineStageSegmentPayload(
        Camera $camera,
        Carbon $focusAt,
        Carbon $reviewWindowStart,
        Carbon $reviewWindowEnd,
    ): ?array {
        $cameraRecordings = $this->reviewRecordings(collect([(int) $camera->getKey()]), $reviewWindowStart, $reviewWindowEnd);
        $recording = $this->timelineReview->selectRecordingForFocus($cameraRecordings, $focusAt);

        return $recording instanceof CameraRecording
            ? $this->timelineReview->recordingReviewPayload($recording, $reviewWindowStart, $reviewWindowEnd)
            : null;
    }

    /**
     * @param  Collection<int, CameraRecording>  $reviewRecordings
     * @return array<int, array<string, mixed>>
     */
    private function initialRailSegments(
        Collection $reviewRecordings,
        int|string|null $activeCameraId,
        Carbon $reviewWindowStart,
        Carbon $reviewWindowEnd,
        int $windowStartMs,
        int $windowEndMs,
    ): array {
        $cameraId = is_numeric($activeCameraId) ? (int) $activeCameraId : 0;

        if ($cameraId <= 0) {
            return [];
        }

        $windowStart = $this->millisecondsToUtc($windowStartMs);
        $windowEnd = $this->millisecondsToUtc($windowEndMs);

        return $reviewRecordings
            ->filter(function (mixed $recording) use ($cameraId, $windowStart, $windowEnd): bool {
                if (!$recording instanceof CameraRecording || (int) $recording->camera_id !== $cameraId) {
                    return false;
                }

                [$recordingStart, $recordingEnd] = $this->timelineReview->recordingBounds($recording);

                return $recordingStart->lessThan($windowEnd)
                    && $recordingEnd->greaterThan($windowStart);
            })
            ->map(fn (CameraRecording $recording): array => $this->timelineReview->recordingReviewPayload($recording, $reviewWindowStart, $reviewWindowEnd))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, int>  $selectedCameraIds
     * @return Collection<int, CameraRecording>
     */
    private function reviewRecordings(Collection $selectedCameraIds, Carbon $reviewWindowStart, Carbon $reviewWindowEnd): Collection
    {
        if ($selectedCameraIds->isEmpty()) {
            return collect();
        }

        $query = CameraRecording::query()
            ->select($this->timelineRecordingColumns())
            ->whereIn('camera_id', $selectedCameraIds->all())
            ->where('status', CameraRecording::STATUS_RECORDED)
            ->whereNotNull('relative_path');

        $this->applyReviewWindow($query, $reviewWindowStart, $reviewWindowEnd);

        return $query
            ->orderBy('camera_id')
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    private function timelineRecordingColumns(): array
    {
        return [
            'id',
            'camera_id',
            'capture_mode',
            'status',
            'scheduled_for',
            'started_at',
            'ended_at',
            'relative_path',
            'file_size_bytes',
            'message',
        ];
    }

    private function applyReviewWindow(Builder $query, Carbon $reviewWindowStart, Carbon $reviewWindowEnd): void
    {
        $query->where(function (Builder $windowQuery) use ($reviewWindowStart, $reviewWindowEnd): void {
            $windowQuery
                ->where(function (Builder $scheduledQuery) use ($reviewWindowStart, $reviewWindowEnd): void {
                    $scheduledQuery
                        ->where('scheduled_for', '>=', $reviewWindowStart)
                        ->where('scheduled_for', '<', $reviewWindowEnd);
                })
                ->orWhere(function (Builder $boundedQuery) use ($reviewWindowStart, $reviewWindowEnd): void {
                    $boundedQuery
                        ->whereNotNull('started_at')
                        ->whereNotNull('ended_at')
                        ->where('started_at', '<', $reviewWindowEnd)
                        ->where('ended_at', '>', $reviewWindowStart);
                });
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $reviewTiles
     */
    private function resolveActiveCameraId(Collection $reviewTiles): int|string|null
    {
        $activeTile = $reviewTiles->first(fn (array $tile): bool => !empty($tile['hasFocusSegment']));

        if (is_array($activeTile) && array_key_exists('cameraId', $activeTile)) {
            return $activeTile['cameraId'];
        }

        $fallbackTile = $reviewTiles->first();

        return is_array($fallbackTile) && array_key_exists('cameraId', $fallbackTile)
            ? $fallbackTile['cameraId']
            : null;
    }

    /**
     * @param  Collection<int, CameraRecording>  $cameraRecordings
     */
    private function selectRecordingForFocus(Collection $cameraRecordings, Carbon $focusAt): ?CameraRecording
    {
        $matchingRecording = $cameraRecordings->first(function (CameraRecording $recording) use ($focusAt): bool {
            [$recordingStart, $recordingEnd] = $this->recordingBounds($recording);

            return $this->timeRangeContainsFocus($recordingStart, $recordingEnd, $focusAt);
        });

        return $matchingRecording instanceof CameraRecording ? $matchingRecording : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function recordingReviewPayload(CameraRecording $recording, Carbon $reviewWindowStart, Carbon $reviewWindowEnd): array
    {
        [$recordingStart, $recordingEnd] = $this->recordingBounds($recording);
        $reviewAssets = app(RecordingReviewAssetService::class);
        $assetState = $reviewAssets->assetState($recording);
        $scrubSprite = $reviewAssets->scrubSpriteMetadata($recording);

        $clippedStart = $recordingStart->greaterThan($reviewWindowStart)
            ? $recordingStart->copy()
            : $reviewWindowStart->copy();
        $clippedEnd = $recordingEnd->lessThan($reviewWindowEnd)
            ? $recordingEnd->copy()
            : $reviewWindowEnd->copy();

        $reviewDurationHours = max(1, $this->timelineDurationHours($reviewWindowStart, $reviewWindowEnd));
        $topHours = min($reviewDurationHours, max(0, $this->timelineHourOffset($reviewWindowStart, $clippedStart)));
        $bottomHours = min($reviewDurationHours, max($topHours + (1 / 3600), $this->timelineHourOffset($reviewWindowStart, $clippedEnd)));
        $spanHours = max(1 / 3600, $bottomHours - $topHours);
        $spanSeconds = max(1, $clippedStart->diffInSeconds($clippedEnd));
        $midpoint = $clippedStart->copy()->addSeconds((int) floor($spanSeconds / 2));
        $modeLabel = $recording->capture_mode === Camera::RECORDING_MODE_MOTION ? 'Movement clip' : 'Continuous clip';
        $durationSeconds = $this->durationSeconds($recording) ?? max(1, $recordingStart->diffInSeconds($recordingEnd));

        return [
            'id' => $recording->getKey(),
            'cameraId' => $recording->camera_id,
            'captureMode' => $recording->capture_mode,
            'modeLabel' => $modeLabel,
            'message' => $recording->message,
            'startMs' => $recordingStart->valueOf(),
            'endMs' => $recordingEnd->valueOf(),
            'midpointMs' => $midpoint->valueOf(),
            'startPercent' => round(($topHours / $reviewDurationHours) * 100, 6),
            'topHours' => round($topHours, 6),
            'topPercent' => round(($topHours / $reviewDurationHours) * 100, 6),
            'widthPercent' => round(($spanHours / $reviewDurationHours) * 100, 6),
            'heightHours' => round($spanHours, 6),
            'heightPercent' => round(($spanHours / $reviewDurationHours) * 100, 6),
            'renderWidthPercent' => round(($spanHours / $reviewDurationHours) * 100, 6),
            'renderHeightHours' => round($spanHours, 6),
            'renderHeightPercent' => round(($spanHours / $reviewDurationHours) * 100, 6),
            'previewStatus' => $assetState['status'],
            'reviewStreamUrl' => $recording->relative_path ? route('recordings.review-stream', ['recording' => $recording]) : null,
            'streamUrl' => $recording->relative_path ? route('recordings.stream', ['recording' => $recording]) : null,
            'thumbnailUrl' => $reviewAssets->thumbnailDataUrl($recording),
            'scrubSpriteUrl' => is_array($scrubSprite) && !empty($scrubSprite['relative_path']) && !empty($scrubSprite['available']) ? route('recordings.preview-sprite', ['recording' => $recording]) : null,
            'scrubFrameCount' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_count'] ?? 0) : 0,
            'scrubFrameIntervalMs' => is_array($scrubSprite) ? ((int) ($scrubSprite['frame_interval_seconds'] ?? 0) * 1000) : 0,
            'scrubFrameWidth' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_width'] ?? 0) : 0,
            'scrubFrameHeight' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_height'] ?? 0) : 0,
            'scrubColumns' => is_array($scrubSprite) ? (int) ($scrubSprite['columns'] ?? 0) : 0,
            'scrubRows' => is_array($scrubSprite) ? (int) ($scrubSprite['rows'] ?? 0) : 0,
            'showUrl' => route('recordings.show', ['recording' => $recording]),
            'downloadUrl' => $recording->relative_path ? route('recordings.download', ['recording' => $recording]) : null,
            'startLabel' => $this->settings->formatDateTime($recordingStart, 'Y-m-d H:i:s') ?? 'Recorded segment',
            'scheduledLabel' => $recording->scheduled_for instanceof Carbon
                ? ($this->settings->formatDateTime($recording->scheduled_for, 'Y-m-d H:i:s') ?? 'Recorded segment')
                : 'Recorded segment',
            'timeLabel' => $this->settings->formatTimeRange($recordingStart, $recordingEnd, 'H:i:s') ?? 'Saved clip',
            'durationLabel' => $durationSeconds.' s',
            'durationSeconds' => $durationSeconds,
            'fileSizeLabel' => $recording->file_size_bytes
                ? number_format($recording->file_size_bytes / 1048576, 2).' MB'
                : 'No file saved',
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function recordingBounds(CameraRecording $recording): array
    {
        $recordingStart = $recording->started_at instanceof Carbon
            ? $recording->started_at->copy()->utc()
            : ($recording->scheduled_for instanceof Carbon
                ? $recording->scheduled_for->copy()->utc()
                : now()->utc());

        $recordingEnd = $recording->ended_at instanceof Carbon
            ? $recording->ended_at->copy()->utc()
            : $recordingStart->copy()->addSeconds(max(1, (int) config('recording.segment_seconds', 60)));

        if ($recordingEnd->lessThanOrEqualTo($recordingStart)) {
            $recordingEnd = $recordingStart->copy()->addSecond();
        }

        return [$recordingStart, $recordingEnd];
    }

    private function timeRangeContainsFocus(Carbon $rangeStart, Carbon $rangeEnd, Carbon $focusAt): bool
    {
        if ($rangeEnd->lessThanOrEqualTo($rangeStart)) {
            return $focusAt->equalTo($rangeStart);
        }

        return $rangeStart->lessThanOrEqualTo($focusAt)
            && $focusAt->lessThan($rangeEnd);
    }

    private function timelineDurationHours(Carbon $reviewWindowStart, Carbon $reviewWindowEnd): float
    {
        return max(1, $this->timelineHourOffset($reviewWindowStart, $reviewWindowEnd));
    }

    private function timelineHourOffset(Carbon $reviewWindowStart, Carbon $moment): float
    {
        $displayWindowStart = $this->settings->toDisplayTimezone($reviewWindowStart)->copy()->startOfHour();
        $displayMoment = $this->settings->toDisplayTimezone($moment);

        if ($displayMoment->lessThanOrEqualTo($displayWindowStart)) {
            return 0.0;
        }

        $displayHourStart = $displayMoment->copy()->startOfHour();
        $elapsedWholeHours = max(0, $displayWindowStart->diffInHours($displayHourStart, false));
        $secondsWithinHour = ($displayMoment->minute * 60) + $displayMoment->second;

        return $elapsedWholeHours + ($secondsWithinHour / 3600);
    }

    /**
     * @param  Collection<int, CameraRecording>  $reviewRecordings
     */
    private function resolveFocusAt(mixed $value, Carbon $reviewWindowStart, Carbon $reviewWindowEnd, Collection $reviewRecordings): Carbon
    {
        if (is_string($value) && trim($value) !== '') {
            $candidate = $this->settings->parseDisplayDateTimeToUtc($value);

            if ($candidate instanceof Carbon) {
                if ($candidate->lessThan($reviewWindowStart)) {
                    return $reviewWindowStart->copy();
                }

                if ($candidate->greaterThanOrEqualTo($reviewWindowEnd)) {
                    return $reviewWindowEnd->copy()->subSecond();
                }

                return $candidate;
            }
        }

        $latestRecording = $reviewRecordings
            ->filter(fn (mixed $recording): bool => $recording instanceof CameraRecording)
            ->sortByDesc(fn (CameraRecording $recording): int => (int) ($recording->ended_at?->getTimestamp() ?? $recording->scheduled_for?->getTimestamp() ?? 0))
            ->first();

        if ($latestRecording instanceof CameraRecording) {
            [$recordingStart] = $this->recordingBounds($latestRecording);
            $latestRecordingFocus = $recordingStart->copy();

            if ($latestRecordingFocus->lessThan($reviewWindowStart)) {
                $latestRecordingFocus = $reviewWindowStart->copy();
            }

            return $latestRecordingFocus->greaterThanOrEqualTo($reviewWindowEnd)
                ? $reviewWindowEnd->copy()->subSecond()
                : $latestRecordingFocus;
        }

        return $reviewWindowStart->copy();
    }

    private function validDateOrNull(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', trim($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function validZoomScaleOrNull(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $zoomScale = (float) $value;

        if ($zoomScale <= 0) {
            return null;
        }

        return round($zoomScale, 3);
    }

    private function requestMilliseconds(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function normalizeTimelineWindowRange(int $dayStartMs, int $dayEndMs, int $windowStartMs, int $windowEndMs): array
    {
        $normalizedStartMs = max($dayStartMs, min($dayEndMs, $windowStartMs));
        $normalizedEndMs = max($normalizedStartMs + 1000, min($dayEndMs, $windowEndMs));

        return [$normalizedStartMs, $normalizedEndMs];
    }

    private function millisecondsToUtc(int $value): Carbon
    {
        return Carbon::createFromTimestampUTC((int) floor($value / 1000));
    }

    private function durationSeconds(CameraRecording $recording): ?int
    {
        if (!$recording->started_at instanceof Carbon || !$recording->ended_at instanceof Carbon) {
            return null;
        }

        return max(0, $recording->started_at->diffInSeconds($recording->ended_at));
    }

    private function previewThumbnailPlaceholder(CameraRecording $recording): string
    {
        $cameraName = htmlspecialchars((string) ($recording->camera?->name ?: 'Camera preview'), ENT_QUOTES, 'UTF-8');
        $timeLabel = htmlspecialchars(
            $recording->scheduled_for instanceof Carbon
                ? ($this->settings->formatDateTime($recording->scheduled_for, 'Y-m-d H:i:s') ?? 'Saved recording')
                : 'Saved recording',
            ENT_QUOTES,
            'UTF-8',
        );

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 180" role="img" aria-label="Timeline preview placeholder">
    <defs>
        <linearGradient id="timeline-preview-gradient" x1="0" y1="0" x2="1" y2="1">
            <stop offset="0%" stop-color="#0f172a" />
            <stop offset="100%" stop-color="#1e293b" />
        </linearGradient>
    </defs>
    <rect width="320" height="180" rx="18" fill="url(#timeline-preview-gradient)" />
    <rect x="16" y="16" width="288" height="148" rx="14" fill="none" stroke="#38bdf8" stroke-opacity="0.28" />
    <text x="160" y="74" text-anchor="middle" fill="#e2e8f0" font-family="Arial, sans-serif" font-size="18" font-weight="700">{$cameraName}</text>
    <text x="160" y="100" text-anchor="middle" fill="#7dd3fc" font-family="Arial, sans-serif" font-size="12">Thumbnail not ready yet</text>
    <text x="160" y="124" text-anchor="middle" fill="#cbd5e1" font-family="Arial, sans-serif" font-size="11">{$timeLabel}</text>
</svg>
SVG;
    }
}