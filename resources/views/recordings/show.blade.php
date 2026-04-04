@extends('layouts.app')

@section('title', config('app.name', 'Bigbrotha').' | Recording review')

@section('body_class', 'page-dashboard')

@section('page_eyebrow', 'Recordings')

@section('page_title', ($camera?->name ?? 'Deleted camera').' recording')

@section('page_lead', 'Review the saved segment, verify the recorder outcome, and download the original file from private storage when needed.')

@section('page_actions')
    <a class="button button--soft" href="{{ route('recordings.index') }}" wire:navigate>Back to browser</a>
    @if ($camera)
        <a class="button button--soft" href="{{ route('camera-fleet.index') }}" wire:navigate>Camera fleet</a>
    @endif
    @if ($playbackAvailable)
        <a class="button button--primary" href="{{ route('recordings.download', ['recording' => $recording]) }}">Download file</a>
    @endif
@endsection

@section('content')
    <section class="screen-card screen-card--spacious">
        <div class="player-layout">
            <div class="player-panel">
                @if (!$playbackAvailable)
                    <div class="empty-state">
                        <strong>This recording is not playable yet.</strong>
                        <p>The segment either failed, was skipped because no motion was detected, or the saved file is no longer present on disk.</p>
                    </div>
                @elseif (!$ffmpegAvailable)
                    <div class="empty-state">
                        <strong>ffmpeg is not available for playback remuxing.</strong>
                        <p>The original file can still be downloaded, but browser playback needs ffmpeg to remux the private segment into fragmented MP4.</p>
                    </div>
                @else
                    <div class="recording-player__shell">
                        <video class="recording-player__video" controls preload="metadata" src="{{ route('recordings.stream', ['recording' => $recording]) }}"></video>
                    </div>
                @endif
            </div>

            <aside class="player-sidebar">
                <div class="screen-card">
                    <div class="panel-heading">
                        <div>
                            <h2 class="panel-title">Recording details</h2>
                            <p class="panel-copy">Inspect the recorder result, timing, capture mode, and saved file metadata for this segment.</p>
                        </div>
                    </div>

                    <div class="key-value-list key-value-list--dense">
                        <div class="key-value-row">
                            <span>Status</span>
                            <strong>{{ ucfirst($recording->status) }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Camera</span>
                            <strong>{{ $camera?->name ?? 'Deleted camera' }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Capture mode</span>
                            <strong>{{ $recording->capture_mode === 'motion' ? 'Record on movement' : 'Constantly recording' }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Scheduled for</span>
                            <strong>{{ $appSettings->formatDateTime($recording->scheduled_for, 'Y-m-d H:i:s') ?? 'Unavailable' }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Duration</span>
                            <strong>{{ $durationSeconds !== null ? $durationSeconds.' seconds' : 'Unavailable' }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>File size</span>
                            <strong>{{ $recording->file_size_bytes ? number_format($recording->file_size_bytes / 1048576, 2).' MB' : 'No file saved' }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Motion score</span>
                            <strong>{{ $recording->motion_score !== null ? $recording->motion_score : 'Not sampled' }}</strong>
                        </div>

                        <div class="key-value-row">
                            <span>Saved path</span>
                            <strong>{{ $recording->relative_path ?? 'No file saved' }}</strong>
                        </div>
                    </div>
                </div>

                <div class="screen-card">
                    <div class="panel-heading">
                        <div>
                            <h2 class="panel-title">Recorder message</h2>
                            <p class="panel-copy">The latest queue or ffmpeg outcome saved for this segment.</p>
                        </div>
                    </div>

                    <div class="empty-state empty-state--compact">
                        <strong>{{ $recording->message ?? 'No recorder message stored.' }}</strong>
                        <p>{{ $recording->created_at?->diffForHumans() ?? 'Just now' }}</p>
                    </div>
                </div>
            </aside>
        </div>
    </section>
@endsection