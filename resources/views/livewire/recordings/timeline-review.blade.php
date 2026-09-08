<div class="recording-review">
    <header class="recording-review__hero">
        <div class="recording-review__hero-copy">
            <h1 class="recording-review__title">Timeline Review</h1>
            <p>Find a moment. Compare cameras. Review the recording.</p>
        </div>
        <a class="button button--soft" href="{{ route('recordings.index') }}" wire:navigate>Back to recordings</a>
    </header>

    @include('livewire.recordings.timeline-filters')
    @if ($timelineCameraOptions === [])
        <section class="screen-card screen-card--spacious">
            <div class="empty-state">
                <strong>No cameras with saved recordings are available for timeline review.</strong>
                <p>Record at least one completed segment first, then return here to review saved clips on the timeline.</p>
                <a class="button button--primary" href="{{ route('camera-fleet.index') }}" wire:navigate>Open camera fleet</a>
            </div>
        </section>
    @else
        @if ($reviewTiles === [])
        <section class="screen-card screen-card--spacious">
            <div class="empty-state">
                <strong>No saved clips are ready for timeline review.</strong>
                <p>No completed clips were found inside the selected date range. Adjust the range above, return to the recordings browser, or wait for current processing to finish.</p>
                <a class="button button--primary" href="{{ route('recordings.index') }}" wire:navigate>Back to recordings</a>
            </div>
        </section>
        @else
        <section
            class="screen-card screen-card--spacious recording-review__workspace"
            data-recording-review-root
            data-day-start-ms="{{ $dayStartMs }}"
            data-day-end-ms="{{ $dayEndMs }}"
            data-focus-ms="{{ $focusAtMs }}"
            data-active-camera-id="{{ $activeCameraId ?? '' }}"
            data-display-timezone="{{ $appSettings->javascriptTimezone() }}"
            data-zoom-scale="{{ number_format($timelineZoomScale, 2, '.', '') }}"
            data-zoom-min-scale="{{ number_format($timelineZoomMinScale, 2, '.', '') }}"
            data-zoom-max-scale="{{ number_format($timelineZoomMaxScale, 2, '.', '') }}"
            data-zoom-step-factor="{{ number_format($timelineZoomStepFactor, 2, '.', '') }}"
            data-rail-base-hour-height-px="{{ $timelineBaseHourHeightPx }}"
            data-rail-min-track-height-px="{{ $timelineMinTrackHeightPx }}"
            data-rail-chunk-duration-ms="{{ $railChunkDurationMs }}"
            data-rail-buffer-duration-ms="{{ $railBufferDurationMs }}"
            data-secondary-tick-interval-minutes="15"
            data-secondary-tick-min-label-spacing-px="20"
            style="--recording-review-hours: {{ $timelineHours }}; --recording-review-rail-hour-height: {{ $timelineBaseHourHeightPx }}px; --recording-review-rail-min-height: {{ $timelineMinTrackHeightPx }}px;"
        >
            <div class="recording-review__context">
                <span>Selected time <strong data-role="focus-label">{{ $focusLabel }}</strong></span>
                <a class="button button--soft recording-review__mobile-link" href="#review-timeline">Timeline ↓</a>
            </div>
            <section class="recording-review-focus" aria-label="Timeline review workspace">
                <div class="recording-review-focus__main">
                    <div id="review-player" class="recording-review__player-anchor" tabindex="-1" wire:ignore>
                        @include('livewire.recordings.timeline-stage', [
                            'tile' => $currentTile ?? [],
                            'segment' => $currentSegment,
                            'focusLabel' => $focusLabel,
                            'reviewRangeLabel' => $reviewRangeLabel,
                            'focusAtMs' => $focusAtMs,
                        ])
                    </div>

                    <section class="recording-review-switcher" aria-label="Loaded cameras">
                        <div class="recording-review-switcher__header">
                            <div>
                                <h2>Cameras <span class="recording-review__count">{{ count($reviewTiles) }}</span></h2>
                                <p>Switch cameras to compare the same moment.</p>
                            </div>
                        </div>

                        <div class="recording-review-switcher__list">
                            @foreach ($reviewTiles as $reviewTile)
                                @php($cameraPreviewUrl = is_string($reviewTile['cameraPreviewUrl'] ?? null) ? trim((string) $reviewTile['cameraPreviewUrl']) : '')
                                @php($cameraPreviewAlt = (string) ($reviewTile['cameraPreviewAlt'] ?? (($reviewTile['cameraName'] ?? 'Camera').' camera preview')))
                                @php($cameraInitials = (string) ($reviewTile['cameraInitials'] ?? ''))
                                <button
                                    class="recording-review-switcher__camera{{ ($activeCameraId ?? null) === (int) ($reviewTile['cameraId'] ?? 0) ? ' is-active' : '' }}"
                                    type="button"
                                    data-role="camera-switch"
                                    data-camera-id="{{ $reviewTile['cameraId'] }}"
                                    data-camera-name="{{ $reviewTile['cameraName'] ?? '' }}"
                                    data-camera-ip="{{ $reviewTile['cameraIp'] ?? '' }}"
                                    data-latest-recording-label="{{ $reviewTile['latestRecordingLabel'] ?? '' }}"
                                    data-rail-url="{{ route('recordings.timeline.rail-data', ['camera' => (int) ($reviewTile['cameraId'] ?? 0)]) }}"
                                    data-stage-url="{{ route('recordings.timeline.stage-data', ['camera' => (int) ($reviewTile['cameraId'] ?? 0)]) }}"
                                    aria-pressed="{{ ($activeCameraId ?? null) === (int) ($reviewTile['cameraId'] ?? 0) ? 'true' : 'false' }}"
                                >
                                    <span class="recording-review-switcher__camera-thumb" data-has-preview="{{ $cameraPreviewUrl !== '' ? 'true' : 'false' }}">
                                        @if ($cameraPreviewUrl !== '')
                                            <img
                                                src="{{ $cameraPreviewUrl }}"
                                                alt="{{ $cameraPreviewAlt }}"
                                                loading="lazy"
                                                decoding="async"
                                                onload="if (!this.parentElement) return; this.parentElement.dataset.hasPreview='true'; if (this.nextElementSibling) { this.nextElementSibling.setAttribute('aria-hidden', 'true'); }"
                                                onerror="if (!this.parentElement) return; this.parentElement.dataset.hasPreview='false'; if (this.nextElementSibling) { this.nextElementSibling.setAttribute('aria-hidden', 'false'); } this.remove();"
                                            >
                                        @endif

                                        <span class="recording-review-switcher__camera-fallback" aria-hidden="{{ $cameraPreviewUrl !== '' ? 'true' : 'false' }}">{{ $cameraInitials !== '' ? $cameraInitials : 'NA' }}</span>
                                    </span>

                                    <span class="recording-review-switcher__camera-copy">
                                        <strong>{{ $reviewTile['cameraName'] }}</strong>
                                        <small>{{ $reviewTile['cameraIp'] ?: 'IP unavailable' }}</small>
                                        <span>{{ $reviewTile['segmentCount'] }} clip{{ (int) ($reviewTile['segmentCount'] ?? 0) === 1 ? '' : 's' }} in range</span>
                                    </span>
                                </button>
                            @endforeach
                        </div>
                    </section>
                </div>

                <div wire:ignore>
                    @include('livewire.recordings.timeline-rail', [
                        'tile' => $currentTile ?? [],
                        'initialSegments' => $initialRailSegments,
                        'initialWindowStartMs' => $initialRailWindowStartMs,
                        'initialWindowEndMs' => $initialRailWindowEndMs,
                        'focusAtMs' => $focusAtMs,
                        'focusLabel' => $focusLabel,
                        'reviewRangeLabel' => $reviewRangeLabel,
                        'dayStartMs' => $dayStartMs,
                        'dayEndMs' => $dayEndMs,
                        'timelineZoomScale' => $timelineZoomScale,
                        'activeSegmentId' => isset($currentSegment['id']) ? (int) $currentSegment['id'] : null,
                        'timelineTicks' => $timelineTicks,
                    ])
                </div>
            </section>
        </section>
        @endif
    @endif
</div>
