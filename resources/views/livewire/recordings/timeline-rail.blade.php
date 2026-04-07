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
    class="recording-review-focus__rail"
    data-role="timeline-rail"
    data-camera-id="{{ $tile['cameraId'] ?? '' }}"
    data-camera-name="{{ $tile['cameraName'] ?? 'Camera' }}"
    data-active-segment-id="{{ $activeSegmentId ?? '' }}"
    data-initial-window-start-ms="{{ $initialWindowStartMs }}"
    data-initial-window-end-ms="{{ $initialWindowEndMs }}"
>
    <div class="recording-review-focus__rail-shell" data-role="rail-shell">
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

        <div class="recording-review-focus__rail-viewport" data-role="rail-viewport">
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

                <div class="recording-review-focus__rail-thumbnails" data-role="rail-thumbnails">
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
                            data-thumbnail-url="{{ $segment['thumbnailUrl'] ?? '' }}"
                            data-thumbnail-alt="{{ ($tile['cameraName'] ?? 'Camera').' '.($segment['timeLabel'] ?? 'Segment preview').' preview' }}"
                            aria-label="{{ ($tile['cameraName'] ?? 'Camera').' '.($segment['timeLabel'] ?? 'Saved clip').' preview thumbnail' }}"
                            title="{{ ($segment['timeLabel'] ?? 'Saved clip').' · '.($segment['modeLabel'] ?? 'Recorded clip') }}"
                            style="top: {{ $thumbnailTopPx }}px;"
                        >
                            <span class="recording-review-focus__rail-thumbnail-frame" data-role="rail-thumbnail-frame">
                                <img src="{{ $segment['thumbnailUrl'] ?? '' }}" alt="{{ $tile['cameraName'] ?? 'Camera' }} {{ $segment['timeLabel'] ?? 'Segment preview' }} preview" loading="lazy" decoding="async">
                            </span>
                        </button>
                    @endforeach
                </div>

                <div
                    class="recording-review-focus__rail-cursor"
                    data-role="focus-cursor"
                    style="top: {{ max(0, min(100, (($focusAtMs - $dayStartMs) / max(1, $dayEndMs - $dayStartMs)) * 100)) }}%;"
                ></div>
            </div>
        </div>
    </div>

</aside>
