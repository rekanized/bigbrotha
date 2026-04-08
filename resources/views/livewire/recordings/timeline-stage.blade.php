@php
    $cameraName = $tile['cameraName'] ?? 'No camera selected';
    $directStreamUrl = is_array($segment) ? ($segment['streamUrl'] ?? null) : null;
    $segmentSourceUrl = $directStreamUrl;
    $assetStatus = $directStreamUrl ? 'Playback stream' : 'No clip selected';
    $fallbackLatest = !empty($tile['latestRecordingLabel'])
        ? 'Latest clip: '.$tile['latestRecordingLabel']
        : 'Choose a camera below or drag the scrub rail onto a saved event.';
    $audioActionLabel = 'Listen to '.$cameraName;
    $audioStatusLabel = 'Muted';
@endphp

<div
    class="recording-review-focus__stage"
    data-role="timeline-stage"
    data-audio-state="muted"
    data-playback-state="playing"
    data-initial-playing="true"
    data-initial-muted="true"
    data-initial-volume="1"
>
    <div class="recording-review-focus__viewer">
        <div class="recording-review-tile__empty recording-review-focus__empty" data-role="empty" @if ($segment) hidden @endif>
            <span class="recording-review-tile__eyebrow" data-role="camera-label">{{ $cameraName }}</span>
            <strong>No recorded segment at the selected time.</strong>
            <p data-role="empty-copy">{{ $fallbackLatest }}</p>
        </div>

        <div class="recording-review-tile__video-shell recording-review-focus__video-shell" data-role="video-shell" wire:ignore @if (!$segment) hidden @endif>
            <video
                class="recording-review-tile__video recording-review-focus__video"
                data-role="video"
                data-recording-id="{{ $segment['id'] ?? '' }}"
                data-start-ms="{{ $segment['startMs'] ?? '' }}"
                data-end-ms="{{ $segment['endMs'] ?? '' }}"
                data-duration-seconds="{{ $segment['durationSeconds'] ?? '' }}"
                data-direct-stream-url="{{ $directStreamUrl ?? '' }}"
                data-direct-status-label="Playback stream"
                autoplay
                playsinline
                preload="metadata"
                muted
                crossorigin="anonymous"
                @if (!empty($segment['thumbnailUrl'])) poster="{{ $segment['thumbnailUrl'] }}" @endif
            >
                <source data-role="video-source" @if ($segmentSourceUrl) src="{{ $segmentSourceUrl }}" @endif type="video/mp4">
                Your browser could not load the timeline review video.
            </video>

            <audio data-role="companion-audio" preload="auto" hidden></audio>

            <div class="recording-review-focus__overlay">
                <div class="recording-review-focus__identity">
                    <span class="recording-review-tile__eyebrow">Active camera</span>
                    <strong data-role="camera-label">{{ $cameraName }}</strong>
                    <p data-role="time-label">{{ $segment['timeLabel'] ?? 'No clip selected' }}</p>
                </div>

                <div class="recording-review-focus__overlay-badges">
                    <span class="status-pill" data-role="mode-label">{{ $segment['modeLabel'] ?? 'Select a clip from the rail' }}</span>
                    <span class="status-pill" data-role="duration-label">{{ $segment['durationLabel'] ?? 'No duration' }}</span>
                    <span class="status-pill" data-role="size-label">{{ $segment['fileSizeLabel'] ?? 'No file saved' }}</span>
                    <span class="status-pill" data-role="asset-status">{{ $assetStatus }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="probe-actions recording-review-focus__actions" @if (!$segment) hidden @endif>
        <div class="recording-review-focus__audio-toolbar">
            <button
                class="recording-review-focus__playback-toggle"
                type="button"
                data-role="playback-toggle"
                aria-pressed="true"
                aria-label="Pause {{ $cameraName }}"
            >
                <span class="recording-review-focus__playback-icon" aria-hidden="true">
                    <svg class="recording-review-focus__playback-svg recording-review-focus__playback-svg--play" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M8 5.5v13l10-6.5-10-6.5z"></path>
                    </svg>
                    <svg class="recording-review-focus__playback-svg recording-review-focus__playback-svg--pause" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M8 5h3v14H8z"></path>
                        <path d="M13 5h3v14h-3z"></path>
                    </svg>
                </span>
                <span class="recording-review-focus__playback-indicator" data-role="playback-indicator" aria-live="polite">Playing</span>
            </button>

            <button
                class="recording-review-focus__audio-toggle"
                type="button"
                data-role="audio-toggle"
                aria-pressed="false"
                aria-label="{{ $audioActionLabel }}"
            >
                <span class="recording-review-focus__audio-icon" aria-hidden="true">
                    <svg class="recording-review-focus__audio-svg recording-review-focus__audio-svg--inactive" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 5L6 9H3v6h3l5 4V5z"></path>
                        <line x1="16.5" y1="9.5" x2="21" y2="14"></line>
                        <line x1="21" y1="9.5" x2="16.5" y2="14"></line>
                    </svg>
                    <svg class="recording-review-focus__audio-svg recording-review-focus__audio-svg--active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M11 5L6 9H3v6h3l5 4V5z"></path>
                        <path d="M15.5 9a4.5 4.5 0 0 1 0 6"></path>
                        <path d="M18.5 6.5a8 8 0 0 1 0 11"></path>
                    </svg>
                </span>
                <span class="recording-review-focus__audio-indicator" data-role="audio-indicator" aria-live="polite">{{ $audioStatusLabel }}</span>
            </button>

            <label class="recording-review-focus__volume" aria-label="Playback volume for {{ $cameraName }}">
                <span class="recording-review-focus__volume-label">Volume</span>
                <input
                    class="recording-review-focus__volume-slider"
                    type="range"
                    min="0"
                    max="100"
                    step="1"
                    value="100"
                    data-role="audio-volume-slider"
                    aria-label="Playback volume for {{ $cameraName }}"
                >
                <span class="recording-review-focus__volume-value" data-role="audio-volume-value">100%</span>
            </label>
        </div>

        <a class="button button--soft" data-role="download-link" href="{{ $segment['downloadUrl'] ?? '#' }}" @if (empty($segment['downloadUrl'])) hidden @endif>Download file</a>
    </div>
</div>