@php
    $segments = is_array($initialSegments ?? null)
        ? array_values(array_filter($initialSegments, static fn (mixed $segment): bool => is_array($segment)))
        : [];

    $tickBufferMs = 15 * 60 * 1000;
    $renderTicks = array_values(array_filter($timelineTicks, static function (array $tick) use ($initialWindowStartMs, $initialWindowEndMs, $tickBufferMs): bool {
        $focusMs = (int) ($tick['focusMs'] ?? 0);

        return $focusMs >= ($initialWindowStartMs - $tickBufferMs)
            && $focusMs <= ($initialWindowEndMs + $tickBufferMs);
    }));

    usort($segments, static fn (array $left, array $right): int => ((int) ($left['startMs'] ?? 0)) <=> ((int) ($right['startMs'] ?? 0)));

    $timelineDurationMs = max(1, $dayEndMs - $dayStartMs);
    $timelineHours = max(1, $timelineDurationMs / 3600000);
    $timelineZoomScale = max(1, (float) ($timelineZoomScale ?? 1.0));
    $hourHeightPx = 88;
    $trackHeightPx = max(1800, (int) ceil($timelineHours * $hourHeightPx * $timelineZoomScale));
    $thumbnailHeightPx = 80;
    $thumbnailGapPx = 12;
    $thumbnailCandidates = [];

    $resolveTrackPlacement = static function (int $startMs, int $endMs, int $timelineStartMs, int $timelineEndMs, int $trackHeightPx): array {
        $timelineDurationMs = max(1, $timelineEndMs - $timelineStartMs);
        $clippedStartMs = max($timelineStartMs, min($timelineEndMs, $startMs));
        $minimumEndMs = max($startMs + 1000, $endMs);
        $clippedEndMs = max($clippedStartMs + 1000, min($timelineEndMs, $minimumEndMs));
        $topRatio = max(0, min(1, ($clippedStartMs - $timelineStartMs) / $timelineDurationMs));
        $heightRatio = max(1000 / $timelineDurationMs, ($clippedEndMs - $clippedStartMs) / $timelineDurationMs);

        return [
            'topPercent' => round($topRatio * 100, 6),
            'heightPercent' => round($heightRatio * 100, 6),
            'topPx' => round($topRatio * $trackHeightPx, 3),
        ];
    };

    foreach ($segments as $idx => $segment) {
        $segmentStartMs = (int) ($segment['startMs'] ?? $dayStartMs);
        $segmentEndMs = (int) ($segment['endMs'] ?? $segmentStartMs);
        $placement = $resolveTrackPlacement($segmentStartMs, $segmentEndMs, $dayStartMs, $dayEndMs, $trackHeightPx);

        $segments[$idx]['_topPercent'] = $placement['topPercent'];
        $segments[$idx]['_heightPercent'] = $placement['heightPercent'];
        $segments[$idx]['_topPx'] = $placement['topPx'];

        $maxThumbnailTopPx = max(0, $trackHeightPx - $thumbnailHeightPx);
        $desiredTopPx = max(0, min($maxThumbnailTopPx, $placement['topPx']));

        $thumbnailCandidates[] = [
            'durationMs' => max(1000, $segmentEndMs - $segmentStartMs),
            'isActive' => $activeSegmentId === (int) ($segment['id'] ?? 0),
            'segment' => $segments[$idx],
            'topPx' => $desiredTopPx,
        ];
    }

    usort($thumbnailCandidates, static function (array $left, array $right): int {
        if (($left['isActive'] ?? false) !== ($right['isActive'] ?? false)) {
            return ($left['isActive'] ?? false) ? -1 : 1;
        }

        if (($left['durationMs'] ?? 0) !== ($right['durationMs'] ?? 0)) {
            return ($right['durationMs'] ?? 0) <=> ($left['durationMs'] ?? 0);
        }

        return ((int) (($left['segment']['startMs'] ?? 0))) <=> ((int) (($right['segment']['startMs'] ?? 0)));
    });

    $thumbnailSegments = [];
    $occupiedThumbnailRanges = [];

    foreach ($thumbnailCandidates as $thumbnailCandidate) {
        $selectedSegment = $thumbnailCandidate['segment'] ?? null;
        $desiredTopPx = (float) ($thumbnailCandidate['topPx'] ?? 0);
        $desiredBottomPx = $desiredTopPx + $thumbnailHeightPx;

        if (!is_array($selectedSegment)) {
            continue;
        }

        $overlapsExistingThumbnail = false;

        foreach ($occupiedThumbnailRanges as $occupiedThumbnailRange) {
            $occupiedTopPx = (float) ($occupiedThumbnailRange['topPx'] ?? 0);
            $occupiedBottomPx = (float) ($occupiedThumbnailRange['bottomPx'] ?? 0);

            if ($desiredTopPx < ($occupiedBottomPx + $thumbnailGapPx)
                && ($desiredBottomPx + $thumbnailGapPx) > $occupiedTopPx) {
                $overlapsExistingThumbnail = true;

                break;
            }
        }

        if ($overlapsExistingThumbnail) {
            continue;
        }

        $selectedSegment['thumbnailTopPx'] = round($desiredTopPx, 3);
        $thumbnailSegments[] = $selectedSegment;
        $occupiedThumbnailRanges[] = [
            'topPx' => $desiredTopPx,
            'bottomPx' => $desiredBottomPx,
        ];
    }

    usort($thumbnailSegments, static fn (array $left, array $right): int => ((int) ($left['startMs'] ?? 0)) <=> ((int) ($right['startMs'] ?? 0)));
@endphp

<aside
    id="review-timeline"
    tabindex="-1"
    aria-label="Recording timeline"
    class="recording-review-focus__rail"
    data-role="timeline-rail"
    data-camera-id="{{ $tile['cameraId'] ?? '' }}"
    data-camera-name="{{ $tile['cameraName'] ?? 'Camera' }}"
    data-active-segment-id="{{ $activeSegmentId ?? '' }}"
    data-initial-window-start-ms="{{ $initialWindowStartMs }}"
    data-initial-window-end-ms="{{ $initialWindowEndMs }}"
    data-rail-url="{{ !empty($tile['cameraId']) ? route('recordings.timeline.rail-data', ['camera' => (int) $tile['cameraId']]) : '' }}"
    data-stage-url="{{ !empty($tile['cameraId']) ? route('recordings.timeline.stage-data', ['camera' => (int) $tile['cameraId']]) : '' }}"
    wire:ignore
>
    <header class="recording-review-focus__rail-header">
        <div>
            <h2>Timeline</h2>
        </div>

        <a class="button button--soft recording-review__mobile-link" href="#review-player">↑ Back to video</a>
        <strong data-role="visible-range-label">{{ $reviewRangeLabel ?? $focusLabel }}</strong>
        <div class="recording-review-focus__rail-toolbar">
            <div class="recording-review-focus__rail-zoom" role="group" aria-label="Timeline zoom">
                <button
                    class="recording-review-focus__rail-zoom-button"
                    type="button"
                    data-role="zoom-out"
                    aria-label="Zoom timeline out"
                    title="Zoom out"
                >−</button>
                <button
                    class="recording-review-focus__rail-zoom-button recording-review-focus__rail-zoom-button--reset"
                    type="button"
                    data-role="zoom-reset"
                    aria-label="Overview: reset timeline zoom"
                    title="Overview: reset zoom"
                >
                    <span>Overview</span>
                    <strong data-role="zoom-label">{{ number_format($timelineZoomScale, 2) }}x</strong>
                </button>
                <button
                    class="recording-review-focus__rail-zoom-button"
                    type="button"
                    data-role="zoom-in"
                    aria-label="Zoom timeline in"
                    title="Zoom in"
                >+</button>
            </div>

            <button class="button button--soft" type="button" data-role="clip-detail" disabled>Detail view</button>
            <button class="button button--soft" type="button" data-role="center-focus">Find selected time</button>
            <details class="recording-review__help">
                <summary>How to use the timeline</summary>
                <p>Scroll or swipe to browse. Select a clip or time label. Drag the blue handle to scrub. Use Ctrl/Command + wheel to zoom.</p>
                <p>With the blue handle focused: arrow keys move 1 second; Shift + arrow moves 1 minute; Page Up/Down moves 15 minutes; Home/End jumps to the range boundary.</p>
            </details>

        </div>
    </header>

    <form class="recording-review__time-jump" method="GET" action="{{ route('recordings.timeline') }}">
        @foreach ($selectedCameraIds ?? [] as $selectedCameraId)
            <input type="hidden" name="camera_ids[]" value="{{ $selectedCameraId }}">
        @endforeach
        <input type="hidden" name="date_from" value="{{ $dateFrom ?? '' }}">
        <input type="hidden" name="date_to" value="{{ $dateTo ?? '' }}">
        <input type="hidden" name="active_camera_id" value="{{ $tile['cameraId'] ?? '' }}" data-role="active-camera-input">
        <input type="hidden" name="zoom" value="{{ $timelineZoomScale }}" data-role="zoom-input">
        <label class="field-stack"><span>Go to time ({{ $appSettings->javascriptTimezone() }})</span><input class="form-input" type="datetime-local" name="focus_at" step="1" value="{{ str_replace(' ', 'T', substr($focusLabel, 0, 19)) }}" min="{{ $dateFrom ?? '' }}T00:00:00" max="{{ $dateTo ?? '' }}T23:59:59" required></label>
        <button class="button button--primary" type="submit">Go</button>
    </form>
    <p class="recording-review__scrub-hint">Drag the blue time handle to select a moment.</p>
    <div class="recording-review__legend" aria-label="Clip colors"><span>● Continuous</span><span>● Movement</span></div>
    <div class="recording-review-focus__rail-shell" data-role="rail-shell">
        <div class="recording-review__rail-feedback">
            <p class="recording-review-focus__rail-status" data-role="rail-status" role="status" aria-live="polite" hidden></p>
            <button class="button button--soft" type="button" data-role="rail-retry" hidden>Retry timeline</button>
        </div>
        <div class="recording-review-focus__scrub-preview" data-role="scrub-preview" hidden>
            <div class="recording-review-focus__scrub-preview-frame" data-role="scrub-preview-frame" data-active-layer-index="0">
                <span class="recording-review-focus__scrub-preview-layer is-active" data-role="scrub-preview-layer" aria-hidden="true"></span>
                <span class="recording-review-focus__scrub-preview-layer" data-role="scrub-preview-layer" aria-hidden="true"></span>
            </div>
            <div class="recording-review-focus__scrub-preview-copy">
                <span class="recording-review-tile__eyebrow">Scrub preview</span>
                <strong data-role="scrub-preview-camera">{{ $tile['cameraName'] ?? 'Camera' }}</strong>
                <p data-role="scrub-preview-time">{{ $focusLabel }}</p>
            </div>
        </div>

        <div
            class="recording-review-focus__rail-viewport"
            data-role="rail-viewport"
            tabindex="0"
            aria-label="Scrollable recording timeline for {{ $tile['cameraName'] ?? 'camera' }}"
            aria-busy="false"
        >
            <div class="recording-review-focus__rail-track" data-role="rail-track" style="height: {{ $trackHeightPx }}px;">
                <div class="recording-review-focus__rail-columns" aria-hidden="true">
                    <div class="recording-review-focus__rail-axis-column"></div>
                    <div class="recording-review-focus__rail-event-column"></div>
                    <div class="recording-review-focus__rail-thumb-column"></div>
                </div>

                <script type="application/json" data-role="rail-ticks-json">@json($timelineTicks)</script>
                <script type="application/json" data-role="rail-segments-json">@json($segments)</script>

                <div class="recording-review-focus__rail-ticks" data-role="rail-ticks">
                    @foreach ($renderTicks as $tick)
                        @php($tickLabelVariant = in_array(($tick['labelVariant'] ?? null), ['day', 'hour', 'minute'], true) ? $tick['labelVariant'] : (!empty($tick['isDayStart']) ? 'day' : (($tick['kind'] ?? 'secondary') === 'primary' ? 'hour' : 'minute')))
                        @php($tickLabelPrimary = $tick['labelPrimary'] ?? null)
                        @php($tickLabelSecondary = $tick['labelSecondary'] ?? ($tick['label'] ?? ''))
                        <button
                            class="recording-review-focus__rail-tick recording-review-focus__rail-tick--{{ $tick['kind'] ?? 'primary' }}{{ !empty($tick['isDayStart']) ? ' is-day-start' : '' }}"
                            type="button"
                            data-role="rail-tick"
                            data-tick-kind="{{ $tick['kind'] ?? 'primary' }}"
                            data-focus-ms="{{ $tick['focusMs'] ?? 0 }}"
                            data-top-percent="{{ number_format((float) ($tick['topPercent'] ?? 0), 6, '.', '') }}"
                            aria-label="{{ $tick['ariaLabel'] ?? ($tick['label'] ?? '') }}"
                            title="{{ $tick['ariaLabel'] ?? ($tick['label'] ?? '') }}"
                            style="top: {{ $tick['topPercent'] ?? 0 }}%; height: {{ $tick['heightPercent'] ?? 0 }}%;"
                        >
                            <span class="recording-review-focus__rail-tick-label recording-review-focus__rail-tick-label--{{ $tickLabelVariant }}" data-role="rail-tick-label">
                                @if ($tickLabelPrimary !== null && $tickLabelPrimary !== '')
                                    <span class="recording-review-focus__rail-tick-part recording-review-focus__rail-tick-part--primary">{{ $tickLabelPrimary }}</span>
                                @endif
                                @if ($tickLabelSecondary !== null && $tickLabelSecondary !== '')
                                    <span class="recording-review-focus__rail-tick-part recording-review-focus__rail-tick-part--secondary">{{ $tickLabelSecondary }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                </div>

                <div class="recording-review-focus__rail-segments" data-role="rail-segments">
                    @foreach ($segments as $segment)
                        @php($captureMode = ($segment['captureMode'] ?? null) === 'motion' ? 'motion' : 'continuous')
                        @php($segmentFocusMs = (int) ($segment['startMs'] ?? 0))
                        @php($segmentTopPercent = number_format((float) ($segment['_topPercent'] ?? 0), 6, '.', ''))
                        @php($segmentHeightPercent = number_format((float) ($segment['_heightPercent'] ?? 0), 6, '.', ''))
                        <button
                            class="recording-review-focus__rail-segment recording-review-focus__rail-segment--{{ $captureMode }}{{ $activeSegmentId === (int) ($segment['id'] ?? 0) ? ' is-active' : '' }}"
                            type="button"
                            data-role="rail-segment"
                            data-recording-id="{{ $segment['id'] ?? '' }}"
                            data-focus-ms="{{ $segmentFocusMs }}"
                            data-start-ms="{{ $segment['startMs'] ?? '' }}"
                            data-end-ms="{{ $segment['endMs'] ?? '' }}"
                            data-top-percent="{{ $segmentTopPercent }}"
                            data-render-height-percent="{{ $segmentHeightPercent }}"
                            data-camera-name="{{ $tile['cameraName'] ?? 'Camera' }}"
                            data-scrub-sprite-url="{{ $segment['scrubSpriteUrl'] ?? '' }}"
                            data-scrub-frame-count="{{ $segment['scrubFrameCount'] ?? 0 }}"
                            data-scrub-frame-width="{{ $segment['scrubFrameWidth'] ?? 0 }}"
                            data-scrub-frame-height="{{ $segment['scrubFrameHeight'] ?? 0 }}"
                            data-scrub-columns="{{ $segment['scrubColumns'] ?? 0 }}"
                            data-scrub-rows="{{ $segment['scrubRows'] ?? 0 }}"
                            data-scrub-frame-interval-ms="{{ $segment['scrubFrameIntervalMs'] ?? 0 }}"
                            aria-label="{{ ($tile['cameraName'] ?? 'Camera').' '.($segment['timeLabel'] ?? 'Saved clip').' '.($segment['modeLabel'] ?? 'Recorded clip') }}"
                            title="{{ ($segment['timeLabel'] ?? 'Saved clip').' · '.($segment['modeLabel'] ?? 'Recorded clip') }}"
                            style="top: {{ $segmentTopPercent }}%; height: {{ $segmentHeightPercent }}%;"
                        >
                            <span class="recording-review-focus__rail-segment-bar"></span>
                        </button>
                    @endforeach
                </div>

                <div class="recording-review-focus__rail-thumbnails" data-role="rail-thumbnails" wire:ignore>
                    @foreach ($thumbnailSegments as $segment)
                        @php($captureMode = ($segment['captureMode'] ?? null) === 'motion' ? 'motion' : 'continuous')
                        @php($segmentFocusMs = (int) ($segment['startMs'] ?? 0))
                        @php($thumbnailTopPx = number_format((float) ($segment['thumbnailTopPx'] ?? $segment['_topPx'] ?? 0), 3, '.', ''))
                        <button
                            class="recording-review-focus__rail-thumbnail recording-review-focus__rail-thumbnail--{{ $captureMode }}{{ $activeSegmentId === (int) ($segment['id'] ?? 0) ? ' is-active' : '' }}"
                            type="button"
                            data-role="rail-thumbnail"
                            data-recording-id="{{ $segment['id'] ?? '' }}"
                            data-focus-ms="{{ $segmentFocusMs }}"
                            data-thumbnail-url="{{ $segment['thumbnailFallbackUrl'] ?? '' }}"
                            data-thumbnail-sprite-url="{{ $segment['thumbnailSpriteUrl'] ?? '' }}"
                            data-thumbnail-frame-index="{{ $segment['thumbnailFrameIndex'] ?? 0 }}"
                            data-scrub-frame-count="{{ $segment['scrubFrameCount'] ?? 0 }}"
                            data-scrub-frame-width="{{ $segment['scrubFrameWidth'] ?? 0 }}"
                            data-scrub-frame-height="{{ $segment['scrubFrameHeight'] ?? 0 }}"
                            data-scrub-columns="{{ $segment['scrubColumns'] ?? 0 }}"
                            data-scrub-rows="{{ $segment['scrubRows'] ?? 0 }}"
                            data-thumbnail-alt="{{ ($tile['cameraName'] ?? 'Camera').' '.($segment['timeLabel'] ?? 'Segment preview').' preview' }}"
                            aria-label="{{ ($tile['cameraName'] ?? 'Camera').' '.($segment['timeLabel'] ?? 'Saved clip').' preview thumbnail' }}"
                            title="{{ ($segment['timeLabel'] ?? 'Saved clip').' · '.($segment['modeLabel'] ?? 'Recorded clip') }}"
                            style="top: {{ $thumbnailTopPx }}px;"
                        >
                            <span class="recording-review-focus__rail-thumbnail-frame" data-role="rail-thumbnail-frame">
                                <span class="recording-review-focus__rail-thumbnail-sprite" data-role="rail-thumbnail-sprite" aria-hidden="true"></span>
                            </span>
                        </button>
                    @endforeach
                </div>

                <div
                    class="recording-review-focus__rail-cursor"
                    data-role="focus-cursor"
                    role="slider"
                    tabindex="0"
                    aria-label="Timeline focus"
                    aria-orientation="vertical"
                    aria-valuemin="{{ $dayStartMs }}"
                    aria-valuemax="{{ max($dayStartMs, $dayEndMs - 1000) }}"
                    aria-valuenow="{{ $focusAtMs }}"
                    aria-valuetext="{{ $focusLabel }}"
                    style="top: {{ max(0, min(100, (($focusAtMs - $dayStartMs) / max(1, $dayEndMs - $dayStartMs)) * 100)) }}%;"
                ><span class="recording-review__focus-time" data-role="rail-focus-time" aria-hidden="true">{{ substr($focusLabel, 11, 8) }}</span></div>
            </div>
        </div>
    </div>

</aside>
