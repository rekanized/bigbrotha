<?php

namespace App\Services\Relay;

use App\Models\Camera;
use App\Services\CameraLiveStreamService;
use App\Services\CameraRecordingService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MediaMtxConfigService
{
    public function __construct(
        private readonly CameraLiveStreamService $streamService,
        private readonly CameraRecordingService $recordingService,
        private readonly MediaMtxPathStatusService $pathStatusService,
        private readonly MediaMtxPathNamer $pathNamer,
    ) {
    }

    /**
     * @return array{mode: 'live', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string, live_transcode: array{quality: string, rate_control: string, bitrate_kbps: int|null}}|null
     */
    public function cameraRelayDefinition(Camera $camera): ?array
    {
        $selection = $this->streamService->selectedWebRtcSource($camera);

        if ($selection === null) {
            return null;
        }

        return [
            'mode' => 'live',
            'path' => $this->cameraPathName($camera),
            'source_path' => $this->sourcePathName($camera, $selection['index']),
            'index' => $selection['index'],
            'profile' => $selection['profile'],
            'authenticated_uri' => $selection['authenticated_uri'],
            'transport' => $selection['transport'],
            'live_transcode' => $camera->liveTranscodeSettings(),
        ];
    }

    public function cameraPathName(Camera $camera): string
    {
        return $this->pathNamer->livePathName($camera);
    }

    public function sourcePathName(Camera $camera, ?int $profileIndex = null): string
    {
        return $this->pathNamer->sourcePathName($camera, $profileIndex);
    }

    /**
     * @return array{mode: 'live', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}|null
     */
    public function cameraLivePlaybackDefinition(Camera $camera): ?array
    {
        $definition = $this->cameraRelayDefinition($camera);

        return $this->playableRelayDefinition($definition);
    }

    /**
     * @return array{mode: 'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string, live_transcode: array{quality: string, rate_control: string, bitrate_kbps: int|null}}|null
     */
    public function cameraRecordingRelayDefinition(Camera $camera, ?int $profileIndex = null): ?array
    {
        return $this->cameraSourceRelayDefinition($camera, $profileIndex);
    }

    /**
     * @return array{mode: 'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string, live_transcode: array{quality: string, rate_control: string, bitrate_kbps: int|null}}|null
     */
    public function cameraSourceRelayDefinition(Camera $camera, ?int $profileIndex = null): ?array
    {
        $selection = $this->recordingService->resolveRecordingSource($camera, $profileIndex);

        if ($selection === null) {
            return null;
        }

        return [
            'mode' => 'source',
            'path' => $this->sourcePathName($camera, $selection['index']),
            'source_path' => $this->sourcePathName($camera, $selection['index']),
            'index' => $selection['index'],
            'profile' => $selection['profile'],
            'authenticated_uri' => $selection['authenticated_uri'],
            'transport' => $selection['transport'],
            'live_transcode' => $camera->liveTranscodeSettings(),
        ];
    }

    /**
     * @return array{mode: 'live', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string, live_transcode: array{quality: string, rate_control: string, bitrate_kbps: int|null}}|null
     */
    public function cameraProfilePlaybackDefinition(Camera $camera, ?int $profileIndex = null): ?array
    {
        $selection = $this->recordingService->resolveRecordingSource($camera, $profileIndex);

        if ($selection === null) {
            return null;
        }

        return [
            'mode' => 'live',
            'path' => $this->pathNamer->liveProfilePathName($camera, $selection['index']),
            'source_path' => $this->sourcePathName($camera, $selection['index']),
            'index' => $selection['index'],
            'profile' => $selection['profile'],
            'authenticated_uri' => $selection['authenticated_uri'],
            'transport' => $selection['transport'],
            'live_transcode' => $camera->liveTranscodeSettings(),
        ];
    }

    /**
     * @return array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string, live_transcode: array{quality: string, rate_control: string, bitrate_kbps: int|null}}|null
     */
    public function cameraMotionEditorRelayDefinition(Camera $camera, ?int $profileIndex = null): ?array
    {
        $sourceDefinition = $this->cameraSourceRelayDefinition($camera, $profileIndex);

        if ($sourceDefinition === null) {
            return null;
        }

        $liveDefinition = $this->cameraLivePlaybackDefinition($camera);

        if ($liveDefinition !== null
            && $this->definitionsMatchProfile($liveDefinition, $sourceDefinition)
            && $this->definitionCanBeReusedForMotionEditor($liveDefinition)) {
            return $liveDefinition;
        }

        return $this->cameraProfilePlaybackDefinition($camera, $sourceDefinition['index']);
    }

    public function recordingPathName(Camera $camera, ?int $profileIndex = null): string
    {
        return $this->sourcePathName($camera, $profileIndex);
    }

    public function browserPlayerUrl(Camera $camera, ?Request $request = null): ?string
    {
        $definition = $this->cameraLivePlaybackDefinition($camera);

        if ($definition === null) {
            return null;
        }

        $baseUrl = $this->browserBaseUrl($request);
        $query = (string) config('mediamtx.webrtc.iframe_query', '');

        return rtrim($baseUrl, '/').'/'.$definition['path'].'/'.($query !== '' ? '?'.$query : '');
    }

    public function browserWhepUrl(Camera $camera, ?Request $request = null): ?string
    {
        $definition = $this->cameraLivePlaybackDefinition($camera);

        if ($definition === null) {
            return null;
        }

        return $this->browserWhepUrlForPath($definition['path'], $request);
    }

    public function browserWhepUrlForPath(string $path, ?Request $request = null): string
    {
        return rtrim($this->browserBaseUrl($request), '/').'/'.$path.'/whep';
    }

    public function browserReaderUrlForPath(string $path, ?Request $request = null): string
    {
        return rtrim($this->browserBaseUrl($request), '/').'/'.$path.'/reader.js';
    }

    public function internalPlayerUrl(string $path): string
    {
        return rtrim((string) config('mediamtx.webrtc.internal_base_url'), '/').'/'.$path;
    }

    /**
     * @return array{video_codec: string, audio_codec: string, audio_channels: int, audio_sample_rate: int}
     */
    public function browserCompatibleStreamFormat(): array
    {
        return [
            'video_codec' => 'h264',
            'audio_codec' => $this->browserAudioCodecName((string) config('mediamtx.transcode.audio_codec', 'libopus')),
            'audio_channels' => max(1, (int) config('mediamtx.transcode.audio_channels', 2)),
            'audio_sample_rate' => max(1, (int) config('mediamtx.transcode.audio_sample_rate', 48000)),
        ];
    }

    public function buildConfig(): string
    {
        $definitions = $this->sourceRelayDefinitions()
            ->merge($this->enabledRelayDefinitions())
            ->merge($this->profilePlaybackDefinitions())
            ->unique('path')
            ->values();
        $ffmpegBinary = $this->streamService->ffmpegBinary();

        $lines = [
            'logLevel: info',
            'logDestinations: [stdout]',
            'readTimeout: 20s',
            'writeTimeout: 20s',
            'api: true',
            'apiAddress: '.config('mediamtx.api.address'),
            'rtsp: true',
            'rtspTransports: [tcp]',
            'rtspAddress: '.config('mediamtx.rtsp.listen_address'),
            'rtmp: false',
            'hls: false',
            'webrtc: true',
            'webrtcAddress: '.config('mediamtx.webrtc.address'),
            'webrtcAllowOrigins: '.$this->inlineStringList((array) config('mediamtx.webrtc.allow_origins', ['*'])),
            'webrtcIPsFromInterfaces: '.(((bool) config('mediamtx.webrtc.ips_from_interfaces', true)) ? 'true' : 'false'),
            'webrtcLocalUDPAddress: '.$this->nullableScalar((string) config('mediamtx.webrtc.local_udp_address', ':8189')),
            'webrtcLocalTCPAddress: '.$this->nullableScalar((string) config('mediamtx.webrtc.local_tcp_address', ':8189')),
        ];

        $additionalHosts = (array) config('mediamtx.webrtc.additional_hosts', []);

        if ($additionalHosts === []) {
            $lines[] = 'webrtcAdditionalHosts: []';
        } else {
            $lines[] = 'webrtcAdditionalHosts:';

            foreach ($additionalHosts as $host) {
                $lines[] = '  - '.$host;
            }
        }

        if ((bool) config('mediamtx.auth.enabled', true)) {
            $lines[] = 'authMethod: http';
            $lines[] = 'authHTTPAddress: '.$this->singleQuotedScalar($this->authCallbackUrl());
            $lines[] = 'authHTTPExclude:';
            $lines[] = '  - action: api';
            $lines[] = '  - action: metrics';
            $lines[] = '  - action: pprof';
        }

        $lines[] = 'pathDefaults:';
        $lines[] = '  sourceOnDemandStartTimeout: '.$this->sourceStartTimeout();
        $lines[] = '  sourceOnDemandCloseAfter: '.config('mediamtx.transcode.close_after', '15s');
        $lines[] = '  overridePublisher: true';
        $lines[] = 'paths:';

        if ($definitions->isEmpty() || $ffmpegBinary === null) {
            $lines[] = '  all_others:';
            $lines[] = '    source: publisher';

            return implode(PHP_EOL, $lines).PHP_EOL;
        }

        foreach ($definitions as $definition) {
            $lines[] = '  '.$definition['path'].':';
            $lines[] = '    source: publisher';
            $lines[] = '    runOnDemand: >-';
            $lines[] = '      '.$this->buildRunOnDemandCommand($ffmpegBinary, $definition);
            $lines[] = '    runOnDemandRestart: false';
            $lines[] = '    runOnDemandStartTimeout: '.$this->runOnDemandStartTimeout($definition);
            $lines[] = '    runOnDemandCloseAfter: '.config('mediamtx.transcode.close_after', '15s');
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
    * @return Collection<int, array{mode: 'live', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}>
     */
    private function enabledRelayDefinitions(): Collection
    {
        return Camera::query()
            ->where('is_enabled', true)
            ->where('supports_rtsp', true)
            ->orderBy('name')
            ->get()
            ->map(fn (Camera $camera): ?array => $this->cameraRelayDefinition($camera))
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array{mode: 'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}>
     */
    private function sourceRelayDefinitions(): Collection
    {
        return Camera::query()
            ->where('supports_rtsp', true)
            ->orderBy('name')
            ->get()
            ->flatMap(function (Camera $camera): array {
                $definitions = [];
                $defaultDefinition = $this->cameraSourceRelayDefinition($camera);

                if ($defaultDefinition !== null) {
                    $definitions[] = $defaultDefinition;
                }

                foreach ($camera->rtspProfiles() as $index => $profile) {
                    if (!is_array($profile) || !is_string($profile['uri'] ?? null) || trim((string) $profile['uri']) === '') {
                        continue;
                    }

                    $definition = $this->cameraSourceRelayDefinition($camera, (int) $index);

                    if ($definition !== null) {
                        $definitions[] = $definition;
                    }
                }

                return $definitions;
            })
            ->values();
    }

    /**
     * @return Collection<int, array{mode: 'live', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}>
     */
    private function profilePlaybackDefinitions(): Collection
    {
        return Camera::query()
            ->where('supports_rtsp', true)
            ->orderBy('name')
            ->get()
            ->flatMap(function (Camera $camera): array {
                $definitions = [];

                foreach ($camera->rtspProfiles() as $index => $profile) {
                    if (!is_array($profile) || !is_string($profile['uri'] ?? null) || trim((string) $profile['uri']) === '') {
                        continue;
                    }

                    $definition = $this->cameraProfilePlaybackDefinition($camera, (int) $index);

                    if ($definition !== null) {
                        $definitions[] = $definition;
                    }
                }

                return $definitions;
            })
            ->values();
    }

    /**
     * @param  array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $left
     * @param  array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $right
     */
    private function definitionsMatchProfile(array $left, array $right): bool
    {
        if (($left['index'] ?? null) !== null && ($right['index'] ?? null) !== null) {
            return $left['index'] === $right['index'];
        }

        return $this->stringOrNull($left['profile']['uri'] ?? null) !== null
            && $this->stringOrNull($left['profile']['uri'] ?? null) === $this->stringOrNull($right['profile']['uri'] ?? null);
    }

    /**
    * @param  array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}|null  $definition
    * @return array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}|null
     */
    private function playableRelayDefinition(?array $definition): ?array
    {
        if (!is_array($definition)) {
            return null;
        }

        if ($this->definitionSupportsColdStart($definition) || $this->definitionCanRetryWhenUnavailable($definition)) {
            return $definition;
        }

        if (isset($this->pathStatusService->activePaths()[$definition['path']])) {
            return $definition;
        }

        return null;
    }

    /**
    * @param  array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $definition
     */
    private function definitionCanRetryWhenUnavailable(array $definition): bool
    {
        $probeSource = $this->stringOrNull($definition['profile']['probe_source'] ?? null);

        return $probeSource === null || strcasecmp($probeSource, 'motion-buffer') !== 0;
    }

    /**
    * @param  array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $definition
     */
    private function definitionSupportsColdStart(array $definition): bool
    {
        $probeStatus = $this->stringOrNull($definition['profile']['probe_status'] ?? null);
        $probeSource = $this->stringOrNull($definition['profile']['probe_source'] ?? null);

        if ($probeStatus === null) {
            return true;
        }

        if (strcasecmp($probeStatus, 'Healthy') !== 0) {
            return false;
        }

        if ($probeSource !== null && strcasecmp($probeSource, 'motion-buffer') === 0) {
            return false;
        }

        if ($probeSource !== null && strcasecmp($probeSource, 'relay') === 0) {
            return true;
        }

        return ($definition['profile']['transport_persistable'] ?? false) === true;
    }

    /**
    * @param  array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $definition
     */
    private function definitionCanBeReusedForMotionEditor(array $definition): bool
    {
        if (isset($this->pathStatusService->activePaths()[$definition['path']])) {
            return true;
        }

        if ($this->stringOrNull($definition['profile']['probe_status'] ?? null) === null) {
            return true;
        }

        return ($definition['profile']['transport_persistable'] ?? false) === true;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function browserBaseUrl(?Request $request = null): string
    {
        $configured = trim((string) config('mediamtx.webrtc.public_base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $scheme = $request?->getScheme() ?? parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'http';
        $host = $request?->getHost() ?? parse_url((string) config('app.url'), PHP_URL_HOST) ?? 'app';
        $port = (int) config('mediamtx.webrtc.port', 8889);

        return $scheme.'://'.$host.($port > 0 ? ':'.$port : '');
    }

    /**
     */
    private function buildRunOnDemandCommand(string $ffmpegBinary, array $definition): string
    {
        return $definition['mode'] === 'source'
            ? $this->buildSourceRunOnDemandCommand(
                $ffmpegBinary,
                $definition['authenticated_uri'],
                $definition['transport'],
                $definition['live_transcode'] ?? [],
            )
            : $this->buildLiveRunOnDemandCommand(
                $ffmpegBinary,
                $definition['profile'],
                $this->internalReaderUrl($definition['source_path']),
                'tcp',
                $definition['live_transcode'] ?? [],
            );
    }

    /**
     * @param  array<string, string|null>  $profile
     * @param  array{quality?: string, rate_control?: string, bitrate_kbps?: int|null}  $transcodeOverrides
     */
    private function buildLiveRunOnDemandCommand(string $ffmpegBinary, array $profile, string $authenticatedUri, string $transport, array $transcodeOverrides = []): string
    {
        $gop = (int) config('mediamtx.transcode.gop', 30);
        $publishTarget = $this->internalPublishUrl('$MTX_PATH');
        $inputAnalyzeDuration = (int) config('ffmpeg.live.input_analyze_duration', config('ffmpeg.streaming.input_analyze_duration', 1000000));
        $inputProbeSize = (int) config('ffmpeg.live.input_probe_size', config('ffmpeg.streaming.input_probe_size', 131072));
        $inputFflags = trim((string) config('ffmpeg.live.input_fflags', '+genpts+discardcorrupt'));
        $fpsMode = trim((string) config('ffmpeg.live.fps_mode', 'passthrough'));
        $avoidNegativeTs = trim((string) config('ffmpeg.live.avoid_negative_ts', 'make_zero'));
        $transcodeVideo = $this->shouldTranscodeVideo($profile, $transcodeOverrides);
        $transcodeFpsMode = trim((string) config('mediamtx.transcode.video_fps_mode', 'cfr'));
        $transcodeFps = max(1, (int) config('mediamtx.transcode.video_fps', 15));
        $transcodeOptions = $this->resolvedLiveTranscodeOptions($transcodeOverrides);
        $forceCompatibilityNormalization = filter_var($transcodeOverrides['force_video_transcode'] ?? false, FILTER_VALIDATE_BOOL);
        $audioResample = $forceCompatibilityNormalization
            ? 'aresample=48000:async=1000:min_hard_comp=0.100:first_pts=0,asetpts=N/SR/TB'
            : trim((string) config('ffmpeg.live.audio_resample', 'aresample=async=1000:min_hard_comp=0.100:first_pts=0'));
        $command = [
            escapeshellarg($ffmpegBinary),
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            escapeshellarg($transport),
            '-thread_queue_size',
            escapeshellarg((string) config('ffmpeg.live.thread_queue_size', 1024)),
            '-timeout',
            escapeshellarg((string) config('ffmpeg.live.rw_timeout', 10000000)),
            '-rtbufsize',
            escapeshellarg((string) config('ffmpeg.live.rtbufsize', '64M')),
            '-fflags',
            escapeshellarg($inputFflags !== '' ? $inputFflags : '+genpts+discardcorrupt'),
            '-use_wallclock_as_timestamps',
            escapeshellarg(config('ffmpeg.live.use_wallclock_timestamps', true) ? '1' : '0'),
            '-analyzeduration',
            escapeshellarg((string) $inputAnalyzeDuration),
            '-probesize',
            escapeshellarg((string) $inputProbeSize),
            ...$this->hardwareAccelerationInputArguments($profile, $transcodeVideo),
            '-i',
            escapeshellarg($authenticatedUri),
            '-map',
            '0:v:0',
            '-map',
            '0:a:0?',
            '-sn',
            '-dn',
        ];

        $effectiveFpsMode = $transcodeVideo ? $transcodeFpsMode : $fpsMode;

        if ($effectiveFpsMode !== '') {
            $command[] = '-fps_mode';
            $command[] = escapeshellarg($effectiveFpsMode);
        }

        if ($avoidNegativeTs !== '') {
            $command[] = '-avoid_negative_ts';
            $command[] = escapeshellarg($avoidNegativeTs);
        }

        if (!$transcodeVideo) {
            $command[] = '-c:v';
            $command[] = 'copy';
            $command[] = '-copyinkf';
        } else {
            $command[] = '-r';
            $command[] = escapeshellarg((string) $transcodeFps);
            array_push($command, ...$this->videoTranscodeArguments($gop, $transcodeOptions));
        }

        array_push($command,
            '-af',
            escapeshellarg($audioResample !== '' ? $audioResample : 'aresample=async=1000:min_hard_comp=0.100:first_pts=0'),
            '-c:a',
            escapeshellarg((string) config('mediamtx.transcode.audio_codec', 'libopus')),
            '-ac',
            escapeshellarg((string) config('mediamtx.transcode.audio_channels', 1)),
            '-ar',
            escapeshellarg((string) config('mediamtx.transcode.audio_sample_rate', 48000)),
            '-b:a',
            escapeshellarg((string) config('mediamtx.transcode.audio_bitrate', '96k')),
            '-max_muxing_queue_size',
            escapeshellarg((string) config('ffmpeg.live.max_muxing_queue_size', 1024)),
            '-f',
            'rtsp',
            '-rtsp_transport',
            'tcp',
            $publishTarget,
        );

        return implode(' ', $command);
    }

    private function buildSourceRunOnDemandCommand(string $ffmpegBinary, string $authenticatedUri, string $transport, array $transcodeOverrides = []): string
    {
        $publishTarget = $this->internalPublishUrl('$MTX_PATH');
        $inputAnalyzeDuration = (int) config('ffmpeg.recording.input_analyze_duration', 1000000);
        $inputProbeSize = (int) config('ffmpeg.recording.input_probe_size', 262144);
        $inputFflags = trim((string) config('ffmpeg.recording.input_fflags', '+genpts+discardcorrupt'));
        $fpsMode = trim((string) config('ffmpeg.recording.fps_mode', 'passthrough'));
        $avoidNegativeTs = trim((string) config('ffmpeg.recording.avoid_negative_ts', 'make_zero'));
        $command = [
            escapeshellarg($ffmpegBinary),
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            escapeshellarg($transport),
            '-thread_queue_size',
            escapeshellarg((string) config('ffmpeg.recording.thread_queue_size', 1024)),
            '-timeout',
            escapeshellarg((string) config('ffmpeg.recording.rw_timeout', 20000000)),
            '-rtbufsize',
            escapeshellarg((string) config('ffmpeg.recording.rtbufsize', '128M')),
            '-fflags',
            escapeshellarg($inputFflags !== '' ? $inputFflags : '+genpts+discardcorrupt'),
            '-use_wallclock_as_timestamps',
            escapeshellarg(
                config('ffmpeg.recording.use_wallclock_timestamps', true)
                && !filter_var($transcodeOverrides['force_video_transcode'] ?? false, FILTER_VALIDATE_BOOL)
                    ? '1'
                    : '0'
            ),
            '-analyzeduration',
            escapeshellarg((string) $inputAnalyzeDuration),
            '-probesize',
            escapeshellarg((string) $inputProbeSize),
            '-i',
            escapeshellarg($authenticatedUri),
            '-map',
            '0:v:0',
            '-map',
            '0:a:0?',
            '-sn',
            '-dn',
        ];

        if ($fpsMode !== '') {
            $command[] = '-fps_mode';
            $command[] = escapeshellarg($fpsMode);
        }

        if ($avoidNegativeTs !== '') {
            $command[] = '-avoid_negative_ts';
            $command[] = escapeshellarg($avoidNegativeTs);
        }

        array_push($command,
            '-c',
            'copy',
            '-copyinkf',
            '-max_muxing_queue_size',
            escapeshellarg((string) config('ffmpeg.recording.max_muxing_queue_size', 1024)),
            '-f',
            'rtsp',
            '-rtsp_transport',
            'tcp',
            $publishTarget,
        );

        return implode(' ', $command);
    }

    /**
     * @param  array<string, string|null>  $profile
     */
    private function shouldCopyVideo(array $profile): bool
    {
        $codec = $this->normalizedVideoCodec($profile);

        return in_array($codec, ['h264', 'h.264'], true);
    }

    /**
     * @param  array<string, string|null>  $profile
     */
    private function shouldTranscodeVideo(array $profile, array $transcodeOverrides = []): bool
    {
        return !$this->shouldCopyVideo($profile)
            || filter_var($transcodeOverrides['force_video_transcode'] ?? false, FILTER_VALIDATE_BOOL)
            || (bool) config('mediamtx.transcode.force_video_transcode', false);
    }

    /**
     * @param  array<string, string|null>  $profile
     * @return array<int, string>
     */
    private function hardwareAccelerationInputArguments(array $profile, bool $transcodeVideo): array
    {
        if (!$transcodeVideo) {
            return [];
        }

        $engine = $this->hardwareAccelerationEngine();

        if ($engine === '') {
            return [];
        }

        $configuredDecoder = $this->stringOrNull(config('mediamtx.transcode.hardware_acceleration.decoder'));
        $decoder = $configuredDecoder ?? $this->hardwareAccelerationDecoder($engine, $profile);
        $device = $this->stringOrNull(config('mediamtx.transcode.hardware_acceleration.device'));

        return match ($engine) {
            'cuda', 'nvidia', 'nvenc' => array_merge(
                $this->nvidiaHardwareInputArguments($decoder, $device),
                $this->configuredHardwareInputArguments(),
            ),
            'qsv', 'intel', 'quicksync' => array_merge(
                $this->quickSyncHardwareInputArguments($decoder, $device),
                $this->configuredHardwareInputArguments(),
            ),
            default => $this->configuredHardwareInputArguments(),
        };
    }

    /**
     * @param  array{preset: string, crf: int, rate_control: string, bitrate: string, maxrate: string, bufsize: string}  $transcodeOptions
     * @return array<int, string>
     */
    private function videoTranscodeArguments(int $gop, array $transcodeOptions): array
    {
        $engine = $this->hardwareAccelerationEngine();

        return match ($engine) {
            'cuda', 'nvidia', 'nvenc' => $this->nvidiaVideoTranscodeArguments($gop, $transcodeOptions),
            'qsv', 'intel', 'quicksync' => $this->quickSyncVideoTranscodeArguments($gop, $transcodeOptions),
            default => $this->softwareVideoTranscodeArguments($gop, $transcodeOptions),
        };
    }

    /**
     * @return array<int, string>
     */
    private function softwareVideoTranscodeArguments(int $gop, array $transcodeOptions): array
    {
        $arguments = [
            '-c:v',
            (string) config('mediamtx.transcode.video_codec', 'libx264'),
            '-pix_fmt',
            'yuv420p',
            '-profile:v',
            'baseline',
            '-preset',
            escapeshellarg($transcodeOptions['preset']),
            '-tune',
            'zerolatency',
            '-bf',
            '0',
            '-refs',
            '1',
            '-g',
            escapeshellarg((string) $gop),
            '-keyint_min',
            escapeshellarg((string) $gop),
            '-sc_threshold',
            '0',
            '-b:v',
            escapeshellarg($transcodeOptions['bitrate']),
            '-maxrate',
            escapeshellarg($transcodeOptions['maxrate']),
            '-bufsize',
            escapeshellarg($transcodeOptions['bufsize']),
        ];

        if ($transcodeOptions['rate_control'] === Camera::LIVE_TRANSCODE_RATE_CONTROL_CBR) {
            $arguments[] = '-minrate';
            $arguments[] = escapeshellarg($transcodeOptions['bitrate']);
            $arguments[] = '-x264-params';
            $arguments[] = escapeshellarg('nal-hrd=cbr:force-cfr=1');

            return $arguments;
        }

        $arguments[] = '-crf';
        $arguments[] = escapeshellarg((string) $transcodeOptions['crf']);
        return $arguments;
    }

    /**
     * @return array<int, string>
     */
    private function nvidiaHardwareInputArguments(?string $decoder, ?string $device): array
    {
        $arguments = [
            '-hwaccel',
            'cuda',
            '-hwaccel_output_format',
            'cuda',
        ];

        if ($device !== null) {
            $arguments[] = '-hwaccel_device';
            $arguments[] = escapeshellarg($device);
        }

        if ($decoder !== null) {
            $arguments[] = '-c:v';
            $arguments[] = $decoder;
        }

        return $arguments;
    }

    /**
     * @return array<int, string>
     */
    private function quickSyncHardwareInputArguments(?string $decoder, ?string $device): array
    {
        $arguments = [];

        if ($device !== null) {
            $arguments[] = '-qsv_device';
            $arguments[] = escapeshellarg($device);
        }

        $arguments[] = '-hwaccel';
        $arguments[] = 'qsv';
        $arguments[] = '-hwaccel_output_format';
        $arguments[] = 'qsv';

        if ($decoder !== null) {
            $arguments[] = '-c:v';
            $arguments[] = $decoder;
        }

        return $arguments;
    }

    /**
     * @return array<int, string>
     */
    private function nvidiaVideoTranscodeArguments(int $gop, array $transcodeOptions): array
    {
        $encoder = $this->stringOrNull(config('mediamtx.transcode.hardware_acceleration.encoder')) ?? 'h264_nvenc';

        return array_merge([
            '-c:v',
            $encoder,
            '-profile:v',
            'baseline',
            '-preset',
            escapeshellarg($transcodeOptions['preset'] === 'ultrafast' ? 'p4' : 'p5'),
            '-tune',
            'll',
            '-bf',
            '0',
            '-g',
            escapeshellarg((string) $gop),
            '-keyint_min',
            escapeshellarg((string) $gop),
            '-rc',
            'cbr',
            '-b:v',
            escapeshellarg($transcodeOptions['bitrate']),
            '-maxrate',
            escapeshellarg($transcodeOptions['maxrate']),
            '-bufsize',
            escapeshellarg($transcodeOptions['bufsize']),
        ], $this->configuredHardwareOutputArguments());
    }

    /**
     * @return array<int, string>
     */
    private function quickSyncVideoTranscodeArguments(int $gop, array $transcodeOptions): array
    {
        $encoder = $this->stringOrNull(config('mediamtx.transcode.hardware_acceleration.encoder')) ?? 'h264_qsv';

        return array_merge([
            '-c:v',
            $encoder,
            '-profile:v',
            'baseline',
            '-preset',
            escapeshellarg($transcodeOptions['preset'] === 'ultrafast' ? 'veryfast' : $transcodeOptions['preset']),
            '-look_ahead',
            '0',
            '-bf',
            '0',
            '-g',
            escapeshellarg((string) $gop),
            '-keyint_min',
            escapeshellarg((string) $gop),
            '-b:v',
            escapeshellarg($transcodeOptions['bitrate']),
            '-maxrate',
            escapeshellarg($transcodeOptions['maxrate']),
            '-bufsize',
            escapeshellarg($transcodeOptions['bufsize']),
        ], $this->configuredHardwareOutputArguments());
    }

    /**
     * @param  array{quality?: string, rate_control?: string, bitrate_kbps?: int|null}  $transcodeOverrides
     * @return array{preset: string, crf: int, rate_control: string, bitrate: string, maxrate: string, bufsize: string}
     */
    private function resolvedLiveTranscodeOptions(array $transcodeOverrides): array
    {
        $quality = strtolower(trim((string) ($transcodeOverrides['quality'] ?? Camera::LIVE_TRANSCODE_QUALITY_DEFAULT)));

        if (!in_array($quality, Camera::LIVE_TRANSCODE_QUALITY_OPTIONS, true)) {
            $quality = Camera::LIVE_TRANSCODE_QUALITY_DEFAULT;
        }

        $rateControl = strtolower(trim((string) ($transcodeOverrides['rate_control'] ?? Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT)));

        if (!in_array($rateControl, Camera::LIVE_TRANSCODE_RATE_CONTROL_OPTIONS, true)) {
            $rateControl = Camera::LIVE_TRANSCODE_RATE_CONTROL_DEFAULT;
        }

        $preset = match ($quality) {
            Camera::LIVE_TRANSCODE_QUALITY_SPEED => 'ultrafast',
            Camera::LIVE_TRANSCODE_QUALITY_BALANCED => 'veryfast',
            Camera::LIVE_TRANSCODE_QUALITY_QUALITY => 'fast',
            default => (string) config('mediamtx.transcode.preset', 'ultrafast'),
        };

        $crf = match ($quality) {
            Camera::LIVE_TRANSCODE_QUALITY_SPEED => 25,
            Camera::LIVE_TRANSCODE_QUALITY_BALANCED => 22,
            Camera::LIVE_TRANSCODE_QUALITY_QUALITY => 20,
            default => (int) config('mediamtx.transcode.video_crf', 23),
        };

        $configuredBitrate = (string) config('mediamtx.transcode.video_bitrate', '1200k');
        $configuredMaxrate = (string) config('mediamtx.transcode.video_maxrate', '1800k');
        $configuredBufsize = (string) config('mediamtx.transcode.video_bufsize', '1800k');
        $bitrateKbps = is_numeric($transcodeOverrides['bitrate_kbps'] ?? null)
            ? max(250, min(20000, (int) $transcodeOverrides['bitrate_kbps']))
            : $this->bitrateStringToKbps($configuredBitrate);

        if ($rateControl === Camera::LIVE_TRANSCODE_RATE_CONTROL_CBR) {
            return [
                'preset' => $preset,
                'crf' => $crf,
                'rate_control' => $rateControl,
                'bitrate' => $this->formatBitrateKbps($bitrateKbps),
                'maxrate' => $this->formatBitrateKbps($bitrateKbps),
                'bufsize' => $this->formatBitrateKbps($bitrateKbps * 2),
            ];
        }

        return [
            'preset' => $preset,
            'crf' => $crf,
            'rate_control' => $rateControl,
            'bitrate' => $configuredBitrate,
            'maxrate' => $configuredMaxrate,
            'bufsize' => $configuredBufsize,
        ];
    }

    private function bitrateStringToKbps(string $value): int
    {
        $normalized = strtolower(trim($value));

        if (preg_match('/^([0-9]+)(k|m)?$/', $normalized, $matches) !== 1) {
            return 1200;
        }

        $amount = (int) $matches[1];
        $unit = $matches[2] ?? 'k';

        return $unit === 'm' ? $amount * 1000 : $amount;
    }

    private function formatBitrateKbps(int $kbps): string
    {
        return max(250, $kbps).'k';
    }

    /**
     * @return array<int, string>
     */
    private function configuredHardwareInputArguments(): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) config('mediamtx.transcode.hardware_acceleration.input_args', []),
        ));
    }

    /**
     * @return array<int, string>
     */
    private function configuredHardwareOutputArguments(): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            (array) config('mediamtx.transcode.hardware_acceleration.output_args', []),
        ));
    }

    /**
     * @param  array<string, string|null>  $profile
     */
    private function normalizedVideoCodec(array $profile): string
    {
        return strtolower(trim((string) ($profile['video_codec'] ?? $profile['encoding'] ?? '')));
    }

    private function browserAudioCodecName(string $codec): string
    {
        $normalized = strtolower(trim($codec));

        return $normalized === 'libopus' ? 'opus' : $normalized;
    }

    private function hardwareAccelerationEngine(): string
    {
        return strtolower(trim((string) config('mediamtx.transcode.hardware_acceleration.engine', '')));
    }

    /**
     * @param  array<string, string|null>  $profile
     */
    private function hardwareAccelerationDecoder(string $engine, array $profile): ?string
    {
        return match ($engine) {
            'cuda', 'nvidia', 'nvenc' => match ($this->normalizedVideoCodec($profile)) {
                'h264', 'h.264' => 'h264_cuvid',
                'hevc', 'h265', 'h.265' => 'hevc_cuvid',
                default => null,
            },
            'qsv', 'intel', 'quicksync' => match ($this->normalizedVideoCodec($profile)) {
                'h264', 'h.264' => 'h264_qsv',
                'hevc', 'h265', 'h.265' => 'hevc_qsv',
                default => null,
            },
            default => null,
        };
    }

    private function internalPublishUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('mediamtx.rtsp.publish_base_url', config('mediamtx.rtsp.internal_base_url')), '/');
        $publisherUser = rawurlencode((string) config('mediamtx.auth.publisher_user', 'publisher'));
        $publisherPass = rawurlencode((string) config('mediamtx.auth.publisher_pass', ''));

        if ($publisherUser === '' || $publisherPass === '' || !str_starts_with($baseUrl, 'rtsp://')) {
            return $baseUrl.'/'.$path;
        }

        return 'rtsp://'.$publisherUser.':'.$publisherPass.'@'.substr($baseUrl, strlen('rtsp://')).'/'.$path;
    }

    private function internalReaderUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('mediamtx.rtsp.internal_base_url'), '/');
        $readerUser = rawurlencode((string) config('mediamtx.auth.reader_user', 'internal-reader'));
        $readerPass = rawurlencode((string) config('mediamtx.auth.reader_pass', ''));

        if ($readerUser === '' || $readerPass === '' || !str_starts_with($baseUrl, 'rtsp://')) {
            return $baseUrl.'/'.$path;
        }

        return 'rtsp://'.$readerUser.':'.$readerPass.'@'.substr($baseUrl, strlen('rtsp://')).'/'.$path;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function inlineStringList(array $values): string
    {
        $encoded = array_map(fn (string $value): string => "'".str_replace("'", "''", $value)."'", $values);

        return '['.implode(', ', $encoded).']';
    }

    private function nullableScalar(string $value): string
    {
        return trim($value) === '' ? "''" : $value;
    }

    /**
     * @param  array{mode: 'live'|'source', path: string, source_path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $definition
     */
    private function runOnDemandStartTimeout(array $definition): string
    {
        if ($definition['mode'] === 'live' && $definition['path'] !== $definition['source_path']) {
            return $this->liveStartTimeout();
        }

        return $this->sourceStartTimeout();
    }

    private function sourceStartTimeout(): string
    {
        return (string) config('mediamtx.transcode.start_timeout', '30s');
    }

    private function liveStartTimeout(): string
    {
        return (string) config('mediamtx.transcode.live_start_timeout', $this->sourceStartTimeout());
    }

    private function authCallbackUrl(): string
    {
        $configured = trim((string) config('mediamtx.auth.callback_url', ''));
        $secret = trim((string) config('mediamtx.auth.callback_secret', ''));

        if ($configured === '' || $secret === '') {
            return $configured;
        }

        $separator = str_contains($configured, '?') ? '&' : '?';

        return $configured.$separator.http_build_query(['secret' => $secret]);
    }

    private function singleQuotedScalar(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
