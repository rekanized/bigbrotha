<?php

namespace App\Services\Relay;

use App\Models\Camera;
use App\Services\CameraLiveStreamService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MediaMtxConfigService
{
    public function __construct(
        private readonly CameraLiveStreamService $streamService,
    ) {
    }

    /**
     * @return array{path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}|null
     */
    public function cameraRelayDefinition(Camera $camera): ?array
    {
        $selection = $this->streamService->selectedWebRtcSource($camera);

        if ($selection === null) {
            return null;
        }

        return [
            'path' => $this->cameraPathName($camera),
            'index' => $selection['index'],
            'profile' => $selection['profile'],
            'authenticated_uri' => $selection['authenticated_uri'],
            'transport' => $selection['transport'],
        ];
    }

    public function cameraPathName(Camera $camera): string
    {
        return 'camera-'.$camera->getKey().'-live';
    }

    public function browserPlayerUrl(Camera $camera, ?Request $request = null): ?string
    {
        $definition = $this->cameraRelayDefinition($camera);

        if ($definition === null) {
            return null;
        }

        $baseUrl = $this->browserBaseUrl($request);
        $query = (string) config('mediamtx.webrtc.iframe_query', '');

        return rtrim($baseUrl, '/').'/'.$definition['path'].'/'.($query !== '' ? '?'.$query : '');
    }

    public function browserWhepUrl(Camera $camera, ?Request $request = null): ?string
    {
        $definition = $this->cameraRelayDefinition($camera);

        if ($definition === null) {
            return null;
        }

        return rtrim($this->browserBaseUrl($request), '/').'/'.$definition['path'].'/whep';
    }

    public function internalPlayerUrl(string $path): string
    {
        return rtrim((string) config('mediamtx.webrtc.internal_base_url'), '/').'/'.$path;
    }

    public function buildConfig(): string
    {
        $definitions = $this->enabledRelayDefinitions();
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
            'webrtcIPsFromInterfaces: true',
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
        $lines[] = '  sourceOnDemandStartTimeout: '.config('mediamtx.transcode.start_timeout', '20s');
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
            $lines[] = '      '.$this->buildRunOnDemandCommand($ffmpegBinary, $definition['authenticated_uri'], $definition['transport']);
            $lines[] = '    runOnDemandRestart: false';
            $lines[] = '    runOnDemandStartTimeout: '.config('mediamtx.transcode.start_timeout', '20s');
            $lines[] = '    runOnDemandCloseAfter: '.config('mediamtx.transcode.close_after', '15s');
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * @return Collection<int, array{path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}>
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

    private function browserBaseUrl(?Request $request = null): string
    {
        $configured = trim((string) config('mediamtx.webrtc.public_base_url', ''));

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $scheme = $request?->getScheme() ?? parse_url((string) config('app.url'), PHP_URL_SCHEME) ?? 'http';
        $host = $request?->getHost() ?? parse_url((string) config('app.url'), PHP_URL_HOST) ?? '127.0.0.1';
        $port = (int) config('mediamtx.webrtc.port', 8889);

        return $scheme.'://'.$host.($port > 0 ? ':'.$port : '');
    }

    private function buildRunOnDemandCommand(string $ffmpegBinary, string $authenticatedUri, string $transport): string
    {
        $gop = (int) config('mediamtx.transcode.gop', 30);
        $publishTarget = $this->internalPublishUrl('$MTX_PATH');

        return implode(' ', [
            escapeshellarg($ffmpegBinary),
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-rtsp_transport',
            escapeshellarg($transport),
            '-fflags',
            'nobuffer',
            '-flags',
            'low_delay',
            '-i',
            escapeshellarg($authenticatedUri),
            '-map',
            '0:v:0',
            '-map',
            '0:a:0?',
            '-c:v',
            'libx264',
            '-pix_fmt',
            'yuv420p',
            '-profile:v',
            'baseline',
            '-preset',
            escapeshellarg((string) config('mediamtx.transcode.preset', 'ultrafast')),
            '-tune',
            'zerolatency',
            '-bf',
            '0',
            '-g',
            escapeshellarg((string) $gop),
            '-keyint_min',
            escapeshellarg((string) $gop),
            '-sc_threshold',
            '0',
            '-b:v',
            escapeshellarg((string) config('mediamtx.transcode.video_bitrate', '1200k')),
            '-c:a',
            escapeshellarg((string) config('mediamtx.transcode.audio_codec', 'libopus')),
            '-ac',
            escapeshellarg((string) config('mediamtx.transcode.audio_channels', 1)),
            '-ar',
            escapeshellarg((string) config('mediamtx.transcode.audio_sample_rate', 48000)),
            '-b:a',
            escapeshellarg((string) config('mediamtx.transcode.audio_bitrate', '96k')),
            '-f',
            'rtsp',
            '-rtsp_transport',
            'tcp',
            $publishTarget,
        ]);
    }

    private function internalPublishUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('mediamtx.rtsp.internal_base_url'), '/');
        $publisherUser = rawurlencode((string) config('mediamtx.auth.publisher_user', 'publisher'));
        $publisherPass = rawurlencode((string) config('mediamtx.auth.publisher_pass', ''));

        if ($publisherUser === '' || $publisherPass === '' || !str_starts_with($baseUrl, 'rtsp://')) {
            return $baseUrl.'/'.$path;
        }

        return 'rtsp://'.$publisherUser.':'.$publisherPass.'@'.substr($baseUrl, strlen('rtsp://')).'/'.$path;
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
