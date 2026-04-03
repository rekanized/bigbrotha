@php
    $segments = is_array($tile['segments'] ?? null)
        ? array_values(array_filter($tile['segments'], static fn (mixed $segment): bool => is_array($segment)))
        : [];

    usort($segments, static fn (array $left, array $right): int => ((int) ($left['startMs'] ?? 0)) <=> ((int) ($right['startMs'] ?? 0)));

    $timelineDurationMs = max(1, $dayEndMs - $dayStartMs);
    $timelineHours = max(1, $timelineDurationMs / 3600000);
    $trackHeightPx = max(1800, (int) ceil($timelineHours * 88));
    $thumbnailHeightPx = 80;
    $thumbnailGapPx = 12;
    $thumbnailSlotPitchPx = $thumbnailHeightPx + $thumbnailGapPx;
    $thumbnailSlotCount = max(1, (int) floor(max(0, $trackHeightPx - $thumbnailHeightPx) / $thumbnailSlotPitchPx) + 1);
    $thumbnailCandidatesBySlot = [];

    foreach ($segments as $segment) {
        $renderTopPercent = (float) ($segment['topPercent'] ?? 0);
        $renderHeightPercent = (float) ($segment['renderHeightPercent'] ?? 0);
        $midpointPercent = max(0, min(100, $renderTopPercent + ($renderHeightPercent / 2)));
        $midpointPx = ($midpointPercent / 100) * $trackHeightPx;
        $maxThumbnailTopPx = max(0, $trackHeightPx - $thumbnailHeightPx);
        $desiredTopPx = max(0, min($maxThumbnailTopPx, $midpointPx - ($thumbnailHeightPx / 2)));
        $slotIndex = (int) round($desiredTopPx / max(1, $thumbnailSlotPitchPx));
        $slotIndex = max(0, min($thumbnailSlotCount - 1, $slotIndex));
        $slotCenterPx = ($slotIndex * $thumbnailSlotPitchPx) + ($thumbnailHeightPx / 2);

        $thumbnailCandidatesBySlot[$slotIndex][] = [
            'distancePx' => abs($midpointPx - $slotCenterPx),
            'heightPercent' => $renderHeightPercent,
            'isActive' => $activeSegmentId === (int) ($segment['id'] ?? 0),
            'segment' => $segment,
        ];
    }

    ksort($thumbnailCandidatesBySlot);

    $thumbnailSegments = [];

    foreach ($thumbnailCandidatesBySlot as $slotIndex => $slotCandidates) {
        usort($slotCandidates, static function (array $left, array $right): int {
            if (($left['isActive'] ?? false) !== ($right['isActive'] ?? false)) {
                return ($left['isActive'] ?? false) ? -1 : 1;
            }

            if (($left['heightPercent'] ?? 0) !== ($right['heightPercent'] ?? 0)) {
                return ($right['heightPercent'] ?? 0) <=> ($left['heightPercent'] ?? 0);
            }

            if (($left['distancePx'] ?? 0) !== ($right['distancePx'] ?? 0)) {
                return ($left['distancePx'] ?? 0) <=> ($right['distancePx'] ?? 0);
            }

            return ((int) (($left['segment']['startMs'] ?? 0))) <=> ((int) (($right['segment']['startMs'] ?? 0)));
        });

        $selectedSegment = $slotCandidates[0]['segment'] ?? null;

        if (!is_array($selectedSegment)) {
            continue;
        }

        $selectedSegment['thumbnailTopPercent'] = round((($slotIndex * $thumbnailSlotPitchPx) / max(1, $trackHeightPx)) * 100, 6);
        $thumbnailSegments[] = $selectedSegment;
    }
@endphp

<aside class="recording-review-focus__rail">
    <div class="recording-review-focus__rail-header">
        <span class="recording-review-tile__eyebrow">Visible range</span>
        <strong data-role="visible-range-label">{{ $reviewRangeLabel }}</strong>
    </div>

    <div class="recording-review-focus__rail-shell">
        <div class="recording-review-focus__rail-viewport" data-role="rail-viewport">
            <div class="recording-review-focus__rail-track" data-role="rail-track">
                <div class="recording-review-focus__rail-columns" aria-hidden="true">
                    <div class="recording-review-focus__rail-axis-column"></div>
                    <div class="recording-review-focus__rail-event-column"></div>
                    <div class="recording-review-focus__rail-thumb-column"></div>
                </div>

                @foreach ($timelineTicks as $tick)
                    <button
                        class="recording-review-focus__rail-tick{{ !empty($tick['isDayStart']) ? ' is-day-start' : '' }}"
                        type="button"
                        data-role="rail-tick"
                        data-focus-ms="{{ $tick['focusMs'] ?? 0 }}"
                        style="top: {{ $tick['topPercent'] ?? 0 }}%; height: {{ $tick['heightPercent'] ?? 0 }}%;"
                    >
                        <span>{{ $tick['label'] ?? '' }}</span>
                    </button>
                @endforeach

                <div class="recording-review-focus__rail-segments" data-role="rail-segments">
                    @foreach ($segments as $segment)
                        @php($captureMode = ($segment['captureMode'] ?? null) === 'motion' ? 'motion' : 'continuous')
                        @php($segmentFocusMs = (int) (($segment['midpointMs'] ?? null) ?: ($segment['startMs'] ?? 0)))
                        <button
                            class="recording-review-focus__rail-segment recording-review-focus__rail-segment--{{ $captureMode }}{{ $activeSegmentId === (int) ($segment['id'] ?? 0) ? ' is-active' : '' }}"
                            type="button"
                            data-role="rail-segment"
                            data-recording-id="{{ $segment['id'] ?? '' }}"
                            data-focus-ms="{{ $segment['midpointMs'] ?? '' }}"
                            data-start-ms="{{ $segment['startMs'] ?? '' }}"
                            data-end-ms="{{ $segment['endMs'] ?? '' }}"
                            data-top-percent="{{ $segment['topPercent'] ?? 0 }}"
                            data-render-height-percent="{{ $segment['renderHeightPercent'] ?? 0 }}"
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
                            style="top: {{ $segment['topPercent'] ?? 0 }}%; height: {{ $segment['renderHeightPercent'] ?? 0 }}%;"
                        >
                            <span class="recording-review-focus__rail-segment-bar"></span>
                        </button>
                    @endforeach
                </div>

                <div class="recording-review-focus__rail-thumbnails">
                    @foreach ($thumbnailSegments as $segment)
                        @php($captureMode = ($segment['captureMode'] ?? null) === 'motion' ? 'motion' : 'continuous')
                        @php($segmentFocusMs = (int) (($segment['midpointMs'] ?? null) ?: ($segment['startMs'] ?? 0)))
                        @php($thumbnailLabel = $segment['scheduledLabel'] ?? ($segment['timeLabel'] ?? 'Saved clip'))
                        <button
                            class="recording-review-focus__rail-thumbnail recording-review-focus__rail-thumbnail--{{ $captureMode }}{{ $activeSegmentId === (int) ($segment['id'] ?? 0) ? ' is-active' : '' }}"
                            type="button"
                            data-role="rail-thumbnail"
                            data-focus-ms="{{ $segmentFocusMs }}"
                            aria-label="{{ ($tile['cameraName'] ?? 'Camera').' '.($segment['timeLabel'] ?? 'Saved clip').' preview thumbnail' }}"
                            title="{{ ($segment['timeLabel'] ?? 'Saved clip').' · '.($segment['modeLabel'] ?? 'Recorded clip') }}"
                            style="top: {{ $segment['thumbnailTopPercent'] ?? 0 }}%;"
                        >
                            <span class="recording-review-focus__rail-thumbnail-frame">
                                <img src="{{ $segment['thumbnailUrl'] ?? '' }}" alt="{{ $tile['cameraName'] ?? 'Camera' }} {{ $segment['timeLabel'] ?? 'Segment preview' }} preview">
                            </span>
                            <span class="recording-review-focus__rail-thumbnail-time">{{ $thumbnailLabel }}</span>
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