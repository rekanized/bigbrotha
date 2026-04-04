<div class="recording-review">
    @if ($timelineCameraOptions === [])
        <section class="screen-card screen-card--spacious">
            <div class="empty-state">
                <strong>No cameras with saved recordings are available for timeline review.</strong>
                <p>Record at least one completed segment first, then return here to review saved clips on the timeline.</p>
                <a class="button button--primary" href="{{ route('camera-fleet.index') }}" wire:navigate>Open camera fleet</a>
            </div>
        </section>
    @elseif ($reviewTiles === [])
        <section class="screen-card screen-card--spacious">
            <div class="empty-state">
                <strong>No saved clips are ready for timeline review.</strong>
                <p>Timeline review only loads completed recorded segments. Return to the recordings browser or wait for current processing to finish, then open this page again.</p>
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
                    <livewire:recordings.timeline-stage
                        :tile="$currentTile ?? []"
                        :segment="$currentSegment"
                        :focus-label="$focusLabel"
                        :review-range-label="$reviewRangeLabel"
                        :focus-at-ms="$focusAtMs"
                        :is-muted="$isMuted"
                        :key="'timeline-stage-'.($activeCameraId ?? 'none').'-'.($currentSegment['id'] ?? 'empty')"
                    />

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
                                    wire:click="selectCamera({{ (int) ($reviewTile['cameraId'] ?? 0) }})"
                                >
                                    <span class="recording-review-switcher__camera-thumb">
                                        @if (!empty($reviewTile['previewThumbnailUrl']))
                                            <img src="{{ $reviewTile['previewThumbnailUrl'] }}" alt="{{ $reviewTile['cameraName'] }} thumbnail preview">
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

                <livewire:recordings.timeline-rail
                    :tile="$currentTile ?? []"
                    :timeline-ticks="$timelineTicks"
                    :focus-at-ms="$focusAtMs"
                    :focus-label="$focusLabel"
                    :review-range-label="$reviewRangeLabel"
                    :day-start-ms="$dayStartMs"
                    :day-end-ms="$dayEndMs"
                    :timeline-zoom-scale="$timelineZoomScale"
                    :active-segment-id="isset($currentSegment['id']) ? (int) $currentSegment['id'] : null"
                    :key="'timeline-rail-'.($currentTile['cameraId'] ?? 'none')"
                />
            </section>
        </section>
    @endif
</div>