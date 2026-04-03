@extends('layouts.app')

@section('title', config('app.name', 'BigBrothas').' | Recording timeline')

@section('body_class', 'page-recording-review')

@section('hide_workspace_hero', 'true')

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
    @endphp

    <livewire:recordings.timeline-review
        :summary="$summary"
        :timeline-camera-options="$timelineCameraOptionsPayload"
        :review-tiles="$reviewTiles->values()->all()"
        :timeline-hours="$timelineHours"
        :timeline-ticks="$timelineTicks->values()->all()"
        :timeline-payload="$timelinePayload"
        :review-range-label="$reviewRangeLabel"
    />
@endsection

@push('scripts')
    <script src="{{ asset('js/recordings-review.js').'?v='.filemtime(public_path('js/recordings-review.js')) }}" defer data-navigate-once></script>
@endpush