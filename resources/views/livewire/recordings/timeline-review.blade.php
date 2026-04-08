<div class="recording-review">
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
                <div class="recording-review__date-range-card recording-review__date-range-card--empty">
                    <form class="recording-review__date-range recording-review__date-range--compact" method="GET" action="{{ route('recordings.timeline') }}">
                        @foreach ($selectedCameraIds as $selectedCameraId)
                            <input type="hidden" name="camera_ids[]" value="{{ $selectedCameraId }}">
                        @endforeach

                        <span class="recording-review__date-range-title">Date range</span>

                        <label class="field-stack field-stack--compact">
                            <span>From</span>
                            <input class="form-input" type="date" name="date_from" value="{{ $dateFrom }}">
                        </label>

                        <label class="field-stack field-stack--compact">
                            <span>To</span>
                            <input class="form-input" type="date" name="date_to" value="{{ $dateTo }}">
                        </label>

                        <div class="probe-actions recording-review__date-range-actions">
                            <button class="button button--primary" type="submit">Apply</button>
                            <a class="button button--soft" href="{{ route('recordings.timeline', $selectedCameraIds !== [] ? ['camera_ids' => $selectedCameraIds] : []) }}" wire:navigate>Default</a>
                        </div>
                    </form>
                </div>
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
            <header class="recording-review__hero">
                <div class="recording-review__hero-copy">
                    <h1 class="recording-review__title">Timeline Review</h1>
                </div>

                <div class="recording-review__hero-actions">
                    <span class="status-pill">Focus <span data-role="focus-label">{{ $focusLabel }}</span></span>
                    <span class="status-pill">Range {{ $reviewRangeLabel }}</span>
                    <a class="button button--soft" href="{{ route('recordings.index') }}" wire:navigate>Back to recordings</a>
                </div>
            </header>

            <div class="recording-review__summary-grid">
                <article class="recording-review__summary-card">
                    <span>Total clips</span>
                    <strong>{{ number_format($summary['total'] ?? 0) }}</strong>
                    <p>Saved segments across the visible review range.</p>
                </article>

                <article class="recording-review__summary-card">
                    <span>Recorded cameras</span>
                    <strong>{{ number_format($summary['cameras'] ?? 0) }}</strong>
                    <p>Recorded feeds available in the camera strip.</p>
                </article>

                <article class="recording-review__summary-card">
                    <span>Movement clips</span>
                    <strong>{{ number_format($summary['motion'] ?? 0) }}</strong>
                    <p>Motion-triggered events inside the loaded time span.</p>
                </article>

                <article class="recording-review__summary-card">
                    <span>Failures</span>
                    <strong>{{ number_format($summary['failed'] ?? 0) }}</strong>
                    <p>Recorder jobs that failed during the same range.</p>
                </article>
            </div>

            <section class="recording-review-focus" aria-label="Timeline review workspace">
                <div class="recording-review-focus__main">
                    <section class="recording-review__date-range-card" aria-label="Timeline date range">
                        <form class="recording-review__date-range recording-review__date-range--compact" method="GET" action="{{ route('recordings.timeline') }}">
                            @foreach ($selectedCameraIds as $selectedCameraId)
                                <input type="hidden" name="camera_ids[]" value="{{ $selectedCameraId }}">
                            @endforeach

                            <span class="recording-review__date-range-title">Date range</span>

                            <label class="field-stack field-stack--compact">
                                <span>From</span>
                                <input class="form-input" type="date" name="date_from" value="{{ $dateFrom }}">
                            </label>

                            <label class="field-stack field-stack--compact">
                                <span>To</span>
                                <input class="form-input" type="date" name="date_to" value="{{ $dateTo }}">
                            </label>

                            <div class="probe-actions recording-review__date-range-actions">
                                <button class="button button--primary" type="submit">Apply</button>
                                <a class="button button--soft" href="{{ route('recordings.timeline', $selectedCameraIds !== [] ? ['camera_ids' => $selectedCameraIds] : []) }}" wire:navigate>Default</a>
                            </div>
                        </form>
                    </section>

                    <div wire:ignore>
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
                                <span class="recording-review-tile__eyebrow">Camera strip</span>
                                <strong>Switch which loaded camera is shown on the stage.</strong>
                                <p>The current timeline focus stays fixed while you change feeds here, so comparing the same moment across loaded cameras stays fast.</p>
                            </div>
                        </div>

                        <div class="recording-review-switcher__list">
                            @foreach ($reviewTiles as $reviewTile)
                                @php($cameraInitialParts = preg_split('/\s+/', trim((string) ($reviewTile['cameraName'] ?? ''))))
                                @php($cameraInitialParts = is_array($cameraInitialParts) ? array_values(array_filter($cameraInitialParts)) : [])
                                @php($cameraInitials = strtoupper(substr((string) ($cameraInitialParts[0] ?? ''), 0, 1).substr((string) ($cameraInitialParts[1] ?? ''), 0, 1)))
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
                                    <span class="recording-review-switcher__camera-thumb">
                                        @if (!empty($reviewTile['previewThumbnailUrl']))
                                            <img src="{{ $reviewTile['previewThumbnailUrl'] }}" alt="{{ $reviewTile['cameraName'] }} thumbnail preview" loading="lazy" decoding="async">
                                        @else
                                            <span class="recording-review-switcher__camera-fallback">{{ $cameraInitials !== '' ? $cameraInitials : 'NA' }}</span>
                                        @endif
                                    </span>

                                    <span class="recording-review-switcher__camera-copy">
                                        <strong>{{ $reviewTile['cameraName'] }}</strong>
                                        <small>{{ $reviewTile['cameraIp'] ?: 'IP unavailable' }}</small>
                                        <span>{{ $reviewTile['segmentCount'] }} clip{{ (int) ($reviewTile['segmentCount'] ?? 0) === 1 ? '' : 's' }}{{ !empty($reviewTile['previewTimeLabel']) ? ' · '.$reviewTile['previewTimeLabel'] : '' }}</span>
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