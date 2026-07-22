@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Recording timeline')

@section('body_class', 'page-recording-review')

@section('hide_workspace_hero', 'true')

@section('page_title', 'Timeline review')

@section('content')
    @php
        $timelineCameraOptionsPayload = $timelineCameraOptions
            ->map(fn ($cameraOption): array => [
                'id' => (int) $cameraOption->id,
                'name' => $cameraOption->name,
                'local_ip' => $cameraOption->local_ip,
            ])
            ->values()
            ->all();

        $reviewTilesPayload = $reviewTiles->values()->all();
        $timelineZoomMinScale = 1.0;
        $timelineZoomMaxScale = (float) max(16, min(96, (int) ceil(max(24, (int) $timelineHours) / 2)));
        $timelineZoomStepFactor = 1.18;
        $timelineBaseHourHeightPx = 88;
        $timelineMinTrackHeightPx = 1800;
        $railChunkDurationMs = 28_800_000;
        $railBufferDurationMs = 14_400_000;
        $dayStartMs = is_numeric($timelinePayload['dayStartMs'] ?? null) ? (int) $timelinePayload['dayStartMs'] : 0;
        $dayEndMs = max(
            $dayStartMs + 1000,
            is_numeric($timelinePayload['dayEndMs'] ?? null) ? (int) $timelinePayload['dayEndMs'] : ($dayStartMs + 1000),
        );
        $timelineMaximumFocusMs = max($dayStartMs, $dayEndMs - 1000);
        $focusAtMs = is_numeric($timelinePayload['focusAtMs'] ?? null) ? (int) $timelinePayload['focusAtMs'] : $dayStartMs;
        $focusAtMs = max($dayStartMs, min($timelineMaximumFocusMs, $focusAtMs));
        $timelineZoomScale = max(
            $timelineZoomMinScale,
            min($timelineZoomMaxScale, (float) ($timelinePayload['zoomScale'] ?? 1.0)),
        );
        $activeCameraId = is_numeric($timelinePayload['activeCameraId'] ?? null) ? (int) $timelinePayload['activeCameraId'] : null;
        $activeCameraId = collect($reviewTilesPayload)->contains(
            fn (array $tile): bool => (int) ($tile['cameraId'] ?? 0) === (int) ($activeCameraId ?? 0),
        )
            ? $activeCameraId
            : (isset($reviewTilesPayload[0]['cameraId']) ? (int) $reviewTilesPayload[0]['cameraId'] : null);
        $currentTile = collect($reviewTilesPayload)
            ->first(fn (array $tile): bool => (int) ($tile['cameraId'] ?? 0) === (int) ($activeCameraId ?? 0))
            ?? ($reviewTilesPayload[0] ?? null);
        $focusLabel = app(\App\Services\ApplicationSettingsService::class)->formatDateTime(
            now()->setTimestamp((int) floor($focusAtMs / 1000))->utc(),
            'Y-m-d H:i:s',
        ) ?? gmdate('Y-m-d H:i:s', (int) floor($focusAtMs / 1000));
        $dateFrom = is_string($dateRange['from'] ?? null) ? $dateRange['from'] : '';
        $dateTo = is_string($dateRange['to'] ?? null) ? $dateRange['to'] : '';
    @endphp

    @include('livewire.recordings.timeline-review', [
        'summary' => $summary,
        'timelineCameraOptions' => $timelineCameraOptionsPayload,
        'timelineTicks' => $timelineTicks,
        'reviewTiles' => $reviewTilesPayload,
        'selectedCameraIds' => $selectedCameraIds,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'timelineHours' => max(24, (int) $timelineHours),
        'timelineZoomScale' => $timelineZoomScale,
        'timelineZoomMinScale' => $timelineZoomMinScale,
        'timelineZoomMaxScale' => $timelineZoomMaxScale,
        'timelineZoomStepFactor' => $timelineZoomStepFactor,
        'timelineBaseHourHeightPx' => $timelineBaseHourHeightPx,
        'timelineMinTrackHeightPx' => $timelineMinTrackHeightPx,
        'railChunkDurationMs' => $railChunkDurationMs,
        'railBufferDurationMs' => $railBufferDurationMs,
        'dayStartMs' => $dayStartMs,
        'dayEndMs' => $dayEndMs,
        'focusAtMs' => $focusAtMs,
        'focusLabel' => $focusLabel,
        'reviewRangeLabel' => $reviewRangeLabel,
        'activeCameraId' => $activeCameraId,
        'currentTile' => $currentTile,
        'currentSegment' => $initialCurrentSegment,
        'initialCurrentSegment' => $initialCurrentSegment,
        'initialRailSegments' => $initialRailSegments,
        'initialRailWindowStartMs' => $initialRailWindowStartMs,
        'initialRailWindowEndMs' => $initialRailWindowEndMs,
    ])
@endsection

@push('scripts')
    <script src="{{ asset('js/recordings-review.js').'?v='.filemtime(public_path('js/recordings-review.js')) }}" defer data-navigate-once></script>
@endpush
