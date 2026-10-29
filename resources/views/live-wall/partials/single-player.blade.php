@if (!($relayStatus['installed'] ?? false))
    <div class="empty-state">
        <strong>Media relay binary is missing from the app container.</strong>
        <p>Rebuild and restart the Docker app service so the configured MediaMTX binary is present before opening the player again.</p>
    </div>
@elseif (!is_array($liveSelection) || !is_string($sessionUrl) || $sessionUrl === '')
    <div class="empty-state">
        <strong>No live RTSP stream is ready.</strong>
        <p>Save or verify this camera’s RTSP path in Camera Fleet, then reopen the player.</p>
    </div>
@else
    <div class="wall-tile__stream">
        <div
            class="webrtc-player webrtc-player--single"
            data-webrtc-player
            data-session-url="{{ $sessionUrl }}"
            data-player-label="{{ $camera->name }}"
            data-expected-video-codec="{{ $streamFormat['video_codec'] ?? 'h264' }}"
            data-expected-audio-codec="{{ $streamFormat['audio_codec'] ?? 'opus' }}"
            data-expected-audio-channels="{{ $streamFormat['audio_channels'] ?? 2 }}"
            data-expected-audio-sample-rate="{{ $streamFormat['audio_sample_rate'] ?? 48000 }}"
        >
            <video class="webrtc-player__video" data-role="video" autoplay muted playsinline controls preload="none"></video>
            <div class="webrtc-player__message" data-role="message" aria-live="polite">Connecting to secure stream...</div>
            <div class="wall-tile__actions">
                <button class="button button--soft" type="button" data-role="audio-toggle" aria-pressed="false" aria-label="Listen to {{ $camera->name }}">
                    <span data-role="audio-toggle-label">Enable audio</span>
                </button>
                <span class="panel-copy" data-role="audio-indicator" aria-live="polite">Muted</span>
            </div>
        </div>
    </div>
@endif
