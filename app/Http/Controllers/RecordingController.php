<?php

namespace App\Http\Controllers;

use App\Models\Camera;
use App\Models\CameraRecording;
use App\Services\CameraRecordingService;
use App\Services\CameraStorageService;
use App\Services\RecordingReviewAssetService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordingController extends Controller
{
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
                $query->whereDate('scheduled_for', '>=', $filters['date_from']);
            })
            ->when($filters['date_to'] !== null, function (Builder $query) use ($filters): void {
                $query->whereDate('scheduled_for', '<=', $filters['date_to']);
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

    public function show(CameraRecording $recording, CameraStorageService $storage, CameraRecordingService $recordings): View
    {
        $recording->loadMissing('camera');
        $absolutePath = $storage->resolveRecordingAbsolutePath($recording->relative_path);
        $durationSeconds = $this->durationSeconds($recording);

        return view('recordings.show', [
            'recording' => $recording,
            'camera' => $recording->camera,
            'durationSeconds' => $durationSeconds,
            'playbackAvailable' => $recording->status === CameraRecording::STATUS_RECORDED && $absolutePath !== null,
            'ffmpegAvailable' => $recordings->ffmpegBinary() !== null,
        ]);
    }

    public function stream(CameraRecording $recording, CameraStorageService $storage, CameraRecordingService $recordings): StreamedResponse
    {
        abort_unless($recording->status === CameraRecording::STATUS_RECORDED, Response::HTTP_NOT_FOUND);
        abort_unless($storage->resolveRecordingAbsolutePath($recording->relative_path) !== null, Response::HTTP_NOT_FOUND);

        return $recordings->playbackResponse($recording->loadMissing('camera'));
    }

    public function reviewStream(CameraRecording $recording, CameraStorageService $storage, CameraRecordingService $recordings): Response
    {
        abort_unless($recording->status === CameraRecording::STATUS_RECORDED, Response::HTTP_NOT_FOUND);
        abort_unless($storage->resolveRecordingAbsolutePath($recording->relative_path) !== null, Response::HTTP_NOT_FOUND);

        return $recordings->bufferedPlaybackResponse($recording->loadMissing('camera'));
    }

    public function previewStream(CameraRecording $recording, RecordingReviewAssetService $reviewAssets, CameraRecordingService $recordings): BinaryFileResponse|StreamedResponse
    {
        abort_unless($recording->status === CameraRecording::STATUS_RECORDED, Response::HTTP_NOT_FOUND);

        $absolutePath = $reviewAssets->previewAbsolutePath($recording);

        if ($absolutePath === null || !is_file($absolutePath)) {
            try {
                $reviewAssets->generateForRecording($recording);
                $absolutePath = $reviewAssets->previewAbsolutePath($recording);
            } catch (\Throwable) {
                return $recordings->playbackResponse($recording->loadMissing('camera'));
            }
        }

        if ($absolutePath === null || !is_file($absolutePath)) {
            return $recordings->playbackResponse($recording->loadMissing('camera'));
        }

        return response()->file($absolutePath, [
            'Content-Type' => 'video/mp4',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function previewThumbnail(CameraRecording $recording, RecordingReviewAssetService $reviewAssets): Response|BinaryFileResponse
    {
        abort_unless($recording->status === CameraRecording::STATUS_RECORDED, Response::HTTP_NOT_FOUND);

        $absolutePath = $reviewAssets->thumbnailAbsolutePath($recording);

        if ($absolutePath === null || !is_file($absolutePath)) {
            try {
                $reviewAssets->generateForRecording($recording);
                $absolutePath = $reviewAssets->thumbnailAbsolutePath($recording);
            } catch (\Throwable) {
                $absolutePath = null;
            }
        }

        if ($absolutePath !== null && is_file($absolutePath)) {
            return response()->file($absolutePath, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, max-age=300',
            ]);
        }

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
            try {
                $reviewAssets->generateForRecording($recording);
                $absolutePath = $reviewAssets->scrubSpriteAbsolutePath($recording);
            } catch (\Throwable) {
                $absolutePath = null;
            }
        }

        if ($absolutePath !== null && is_file($absolutePath)) {
            return response()->file($absolutePath, [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'private, max-age=300',
            ]);
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

        $extension = pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'mkv';
        $fileName = Str::slug($recording->camera?->name ?: 'camera-recording').'-'.($recording->scheduled_for?->format('Ymd_His') ?? 'segment').'.'.$extension;

        return response()->download($absolutePath, $fileName, [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * @return Collection<int, Camera>
     */
    private function cameraOptions(): Collection
    {
        return Camera::query()
            ->whereHas('recordings')
            ->orderBy('name')
            ->get(['id', 'name', 'local_ip']);
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineViewData(Request $request): array
    {
        $requestedDay = $this->validDateOrNull($request->query('day'));

        $timelineCameraOptions = $this->cameraOptions();
        $selectedCameraIds = $this->resolveTimelineCameraIds($request, $timelineCameraOptions);
        $selectedCameras = $timelineCameraOptions
            ->filter(fn (Camera $camera): bool => $selectedCameraIds->contains((int) $camera->getKey()))
            ->values();

        [$reviewWindowStart, $reviewWindowEnd] = $this->reviewTimelineBounds($selectedCameraIds, $requestedDay);
        $reviewRecordings = $this->reviewRecordings($selectedCameraIds, $reviewWindowStart, $reviewWindowEnd);
        $focusAt = $this->resolveFocusAt(
            $request->query('focus_at') ?? $requestedDay,
            $reviewWindowStart,
            $reviewWindowEnd,
            $reviewRecordings,
        );
        $reviewTiles = $this->buildReviewTiles($selectedCameras, $reviewRecordings, $focusAt, $reviewWindowStart, $reviewWindowEnd);
        $timelineHours = max(24, (int) ceil($reviewWindowStart->diffInMinutes($reviewWindowEnd) / 60));
        $timelineTicks = $this->timelineTicks($reviewWindowStart, $reviewWindowEnd);
        $activeCameraId = $this->resolveActiveCameraId($reviewTiles);
        $summaryQuery = CameraRecording::query()
            ->when($selectedCameraIds->isNotEmpty(), function (Builder $query) use ($selectedCameraIds): void {
                $query->whereIn('camera_id', $selectedCameraIds->all());
            })
            ->where(function (Builder $query) use ($reviewWindowStart, $reviewWindowEnd): void {
                $query->where('scheduled_for', '>=', $reviewWindowStart)
                    ->where('scheduled_for', '<', $reviewWindowEnd);
            });

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
            'reviewRangeLabel' => $reviewWindowStart->format('Y-m-d H:i').' UTC - '.$reviewWindowEnd->copy()->subSecond()->format('Y-m-d H:i').' UTC',
            'reviewTiles' => $reviewTiles,
            'timelineHours' => $timelineHours,
            'timelineTicks' => $timelineTicks,
            'timelinePayload' => [
                'activeCameraId' => $activeCameraId,
                'dayStartMs' => $reviewWindowStart->valueOf(),
                'dayEndMs' => $reviewWindowEnd->valueOf(),
                'focusAtMs' => $focusAt->valueOf(),
                'cacheMaxEntries' => (int) config('recording.review_assets.cache_max_entries', 36),
                'cacheMaxBytes' => (int) config('recording.review_assets.cache_max_bytes', 157286400),
                'zoomHoursWidth' => 140,
                'timelineHours' => $timelineHours,
                'tiles' => $reviewTiles->values()->all(),
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
    private function reviewTimelineBounds(Collection $selectedCameraIds, ?string $requestedDay): array
    {
        $fallbackAnchor = $requestedDay !== null
            ? Carbon::createFromFormat('Y-m-d', $requestedDay, 'UTC')->utc()->startOfDay()
            : now()->utc()->startOfDay();
        $fallbackStart = $fallbackAnchor->copy()->subDays(3);
        $fallbackEnd = $fallbackStart->copy()->addDays(7);

        if ($selectedCameraIds->isEmpty()) {
            return [$fallbackStart, $fallbackEnd];
        }

        $bounds = CameraRecording::query()
            ->whereIn('camera_id', $selectedCameraIds->all())
            ->where('status', CameraRecording::STATUS_RECORDED)
            ->selectRaw('MIN(COALESCE(started_at, scheduled_for)) as min_recording_at')
            ->selectRaw('MAX(COALESCE(ended_at, started_at, scheduled_for)) as max_recording_at')
            ->first();

        $minRecordingAt = $bounds?->min_recording_at ? Carbon::parse($bounds->min_recording_at, 'UTC')->utc() : null;
        $maxRecordingAt = $bounds?->max_recording_at ? Carbon::parse($bounds->max_recording_at, 'UTC')->utc() : null;

        if (!$minRecordingAt instanceof Carbon || !$maxRecordingAt instanceof Carbon) {
            return [$fallbackStart, $fallbackEnd];
        }

        $timelineStart = $minRecordingAt->copy()->startOfDay()->subDays(3);
        $timelineEnd = $maxRecordingAt->copy()->addDay()->startOfDay()->addDays(3);

        if ($timelineEnd->diffInHours($timelineStart) < (24 * 7)) {
            $timelineEnd = $timelineStart->copy()->addDays(7);
        }

        if ($timelineEnd->lessThanOrEqualTo($timelineStart)) {
            $timelineEnd = $timelineStart->copy()->addDays(7);
        }

        return [$timelineStart, $timelineEnd];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function timelineTicks(Carbon $reviewWindowStart, Carbon $reviewWindowEnd): Collection
    {
        $ticks = collect();
        $cursor = $reviewWindowStart->copy();
        $totalHours = max(1, (int) ceil($reviewWindowStart->diffInMinutes($reviewWindowEnd) / 60));

        while ($cursor->lessThan($reviewWindowEnd)) {
            $offsetHours = $reviewWindowStart->diffInMinutes($cursor) / 60;

            $ticks->push([
                'focusMs' => $cursor->valueOf(),
                'leftPercent' => round(($offsetHours / $totalHours) * 100, 6),
                'topPercent' => round(($offsetHours / $totalHours) * 100, 6),
                'widthPercent' => round((1 / $totalHours) * 100, 6),
                'heightPercent' => round((1 / $totalHours) * 100, 6),
                'isDayStart' => $cursor->isStartOfDay(),
                'label' => $cursor->isStartOfDay()
                    ? $cursor->format('M d')
                    : $cursor->format('H:i'),
            ]);

            $cursor->addHour();
        }

        return $ticks;
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
            ->with('camera')
            ->whereIn('camera_id', $selectedCameraIds->all())
            ->where('status', CameraRecording::STATUS_RECORDED);

        $this->applyReviewWindow($query, $reviewWindowStart, $reviewWindowEnd);

        return $query
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();
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
                        ->where('ended_at', '>=', $reviewWindowStart);
                });
        });
    }

    /**
     * @param  Collection<int, Camera>  $selectedCameras
     * @param  Collection<int, CameraRecording>  $reviewRecordings
     * @return Collection<int, array<string, mixed>>
     */
    private function buildReviewTiles(
        Collection $selectedCameras,
        Collection $reviewRecordings,
        Carbon $focusAt,
        Carbon $reviewWindowStart,
        Carbon $reviewWindowEnd,
    ): Collection {
        $recordingsByCamera = $reviewRecordings
            ->filter(fn (mixed $recording): bool => $recording instanceof CameraRecording)
            ->groupBy('camera_id');

        return $selectedCameras->map(function (Camera $camera) use ($focusAt, $recordingsByCamera, $reviewWindowStart, $reviewWindowEnd): array {

            /** @var Collection<int, CameraRecording> $cameraRecordings */
            $cameraRecordings = $recordingsByCamera->get($camera->getKey(), collect())
                ->sortBy(fn (CameraRecording $recording): int => (int) ($recording->scheduled_for?->getTimestamp() ?? 0))
                ->values();

            $segments = $cameraRecordings
                ->map(fn (CameraRecording $recording): array => $this->recordingReviewPayload($recording, $reviewWindowStart, $reviewWindowEnd))
                ->values();

            $selectedRecording = $this->selectRecordingForFocus($cameraRecordings, $focusAt);
            $latestRecording = $cameraRecordings->last();
            $selectedRecordingPayload = $selectedRecording instanceof CameraRecording
                ? $this->recordingReviewPayload($selectedRecording, $reviewWindowStart, $reviewWindowEnd)
                : null;
            $latestRecordingPayload = $latestRecording instanceof CameraRecording
                ? $this->recordingReviewPayload($latestRecording, $reviewWindowStart, $reviewWindowEnd)
                : null;
            $previewPayload = $selectedRecordingPayload ?? $latestRecordingPayload;

            return [
                'cameraId' => $camera->getKey(),
                'cameraName' => $camera->name,
                'cameraIp' => $camera->local_ip,
                'orientation' => 'landscape',
                'columnSpan' => 1,
                'rowSpan' => 1,
                'segments' => $segments->all(),
                'segmentCount' => $segments->count(),
                'selectedRecording' => $selectedRecordingPayload,
                'latestRecordingLabel' => $latestRecordingPayload['timeLabel'] ?? null,
                'previewThumbnailUrl' => $previewPayload['thumbnailUrl'] ?? null,
                'previewTimeLabel' => $previewPayload['timeLabel'] ?? null,
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $reviewTiles
     */
    private function resolveActiveCameraId(Collection $reviewTiles): int|string|null
    {
        $activeTile = $reviewTiles->first(fn (array $tile): bool => is_array($tile['selectedRecording'] ?? null));

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

            return $recordingStart->lessThanOrEqualTo($focusAt) && $recordingEnd->greaterThanOrEqualTo($focusAt);
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

        $reviewDurationSeconds = max(1, $reviewWindowStart->diffInSeconds($reviewWindowEnd));
        $offsetSeconds = max(0, $reviewWindowStart->diffInSeconds($clippedStart));
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
            'startPercent' => round(($offsetSeconds / $reviewDurationSeconds) * 100, 6),
            'topPercent' => round(($offsetSeconds / $reviewDurationSeconds) * 100, 6),
            'widthPercent' => round(($spanSeconds / $reviewDurationSeconds) * 100, 6),
            'heightPercent' => round(($spanSeconds / $reviewDurationSeconds) * 100, 6),
            'renderWidthPercent' => max(0.7, round(($spanSeconds / $reviewDurationSeconds) * 100, 6)),
            'renderHeightPercent' => max(0.22, round(($spanSeconds / $reviewDurationSeconds) * 100, 6)),
            'previewStatus' => $assetState['status'],
            'preferredStreamUrl' => $assetState['preview_available'] ? route('recordings.preview-stream', ['recording' => $recording]) : null,
            'streamUrl' => $recording->relative_path ? route('recordings.stream', ['recording' => $recording]) : null,
            'thumbnailUrl' => route('recordings.preview-thumbnail', ['recording' => $recording]),
            'scrubSpriteUrl' => is_array($scrubSprite) && !empty($scrubSprite['relative_path']) ? route('recordings.preview-sprite', ['recording' => $recording]) : null,
            'scrubFrameCount' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_count'] ?? 0) : 0,
            'scrubFrameIntervalMs' => is_array($scrubSprite) ? ((int) ($scrubSprite['frame_interval_seconds'] ?? 0) * 1000) : 0,
            'scrubFrameWidth' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_width'] ?? 0) : 0,
            'scrubFrameHeight' => is_array($scrubSprite) ? (int) ($scrubSprite['frame_height'] ?? 0) : 0,
            'scrubColumns' => is_array($scrubSprite) ? (int) ($scrubSprite['columns'] ?? 0) : 0,
            'scrubRows' => is_array($scrubSprite) ? (int) ($scrubSprite['rows'] ?? 0) : 0,
            'showUrl' => route('recordings.show', ['recording' => $recording]),
            'downloadUrl' => $recording->relative_path ? route('recordings.download', ['recording' => $recording]) : null,
            'scheduledLabel' => $recording->scheduled_for instanceof Carbon
                ? $recording->scheduled_for->format('Y-m-d H:i:s').' UTC'
                : 'Recorded segment',
            'timeLabel' => $recordingStart->format('H:i:s').' - '.$recordingEnd->format('H:i:s').' UTC',
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

    /**
     * @param  Collection<int, CameraRecording>  $reviewRecordings
     */
    private function resolveFocusAt(mixed $value, Carbon $reviewWindowStart, Carbon $reviewWindowEnd, Collection $reviewRecordings): Carbon
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                $candidate = Carbon::parse(trim($value), 'UTC')->utc();

                if ($candidate->lessThan($reviewWindowStart)) {
                    return $reviewWindowStart->copy();
                }

                if ($candidate->greaterThanOrEqualTo($reviewWindowEnd)) {
                    return $reviewWindowEnd->copy()->subSecond();
                }

                return $candidate;
            } catch (\Throwable) {
            }
        }

        $latestRecording = $reviewRecordings
            ->filter(fn (mixed $recording): bool => $recording instanceof CameraRecording)
            ->sortByDesc(fn (CameraRecording $recording): int => (int) ($recording->ended_at?->getTimestamp() ?? $recording->scheduled_for?->getTimestamp() ?? 0))
            ->first();

        if ($latestRecording instanceof CameraRecording) {
            [, $recordingEnd] = $this->recordingBounds($latestRecording);

            return $recordingEnd->greaterThanOrEqualTo($reviewWindowEnd)
                ? $reviewWindowEnd->copy()->subSecond()
                : $recordingEnd;
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
                ? $recording->scheduled_for->copy()->utc()->format('Y-m-d H:i:s').' UTC'
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