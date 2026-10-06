<?php

namespace App\Services\Onvif;

use App\Models\Camera;
use App\Services\CameraStorageService;
use App\Services\Concerns\ResolvesConfiguredBinaries;
use App\Services\Relay\MediaMtxConfigService;
use App\Services\Relay\MediaMtxPathStatusService;
use App\Support\CameraUrl;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class RtspStreamDiagnosticsService
{
    use ResolvesConfiguredBinaries;

    /**
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    public function __construct(
        private readonly MediaMtxConfigService $relayConfig,
        private readonly MediaMtxPathStatusService $pathStatusService,
    ) {}

    public function testAndPreview(Camera $camera, array $profile, int $profileIndex, int $timeoutSeconds = 12): array
    {
        $uri = $this->stringOrNull($profile['uri'] ?? null);

        if ($uri === null) {
            throw new RuntimeException('This RTSP profile does not have a stream URI yet. Refresh RTSP profiles first.');
        }

        $ffprobeBinary = $this->resolveBinary(config('ffmpeg.ffprobe.binaries', []));
        $ffmpegBinary = $this->resolveBinary(config('ffmpeg.ffmpeg.binaries', []));

        if ($ffprobeBinary === null || $ffmpegBinary === null) {
            throw new RuntimeException('ffprobe or ffmpeg is not available on this host. Check the recorder stack configuration first.');
        }

        $transport = in_array($camera->rtsp_transport, ['tcp', 'udp'], true) ? $camera->rtsp_transport : 'tcp';
        $authenticatedUri = $this->injectCredentials($uri, $camera->username, $camera->password);
        $probeCheckedAt = now()->utc()->format('Y-m-d H:i:s').' UTC';
        $previewPath = $this->buildPreviewRelativePath($camera, $profile, $profileIndex);
        $storage = app(CameraStorageService::class);
        $absolutePreviewPath = $storage->writableAbsolutePath($previewPath);
        $directSources = $this->directSources($transport, $authenticatedUri);
        $directFailures = [];
        $fallbackFailures = [];
        $successfulSource = null;
        $streamInfo = [
            'codec_name' => $this->stringOrNull($profile['video_codec'] ?? null),
            'resolution' => $this->stringOrNull($profile['video_resolution'] ?? null),
        ];

        foreach ($directSources as $source) {
            $probe = $this->runProbe($ffprobeBinary, $source['uri'], $source['transport'], $timeoutSeconds);

            if ($probe['successful']) {
                $successfulSource = $source;
                $streamInfo = $probe['stream'];

                break;
            }

            $directFailures[] = [
                'label' => $source['label'],
                'message' => $probe['message'],
            ];
        }

        $storage->ensureCameraDirectories($camera);

        if ($successfulSource !== null) {
            $preview = $this->capturePreview(
                $ffmpegBinary,
                $successfulSource['uri'],
                $successfulSource['transport'],
                $absolutePreviewPath,
                $previewPath,
                $storage,
                $timeoutSeconds,
            );

            if (! $preview['successful']) {
                foreach ($this->relaySources($camera, $profile, $profileIndex) as $relaySource) {
                    $relayPreview = $this->capturePreview(
                        $ffmpegBinary,
                        $relaySource['uri'],
                        $relaySource['transport'],
                        $absolutePreviewPath,
                        $previewPath,
                        $storage,
                        $timeoutSeconds,
                    );

                    if ($relayPreview['successful']) {
                        return $this->successfulProfile(
                            $profile,
                            $streamInfo,
                            $probeCheckedAt,
                            $previewPath,
                            $storage,
                            'Direct RTSP playback was blocked by the camera, so stream verification used the active shared relay instead.',
                            'Preview captured successfully through the active shared relay because the camera rejected an additional direct session.',
                            $relaySource['transport'],
                            false,
                            'relay',
                        );
                    }
                }
            }

            return $this->successfulProfile(
                $profile,
                $streamInfo,
                $probeCheckedAt,
                $previewPath,
                $storage,
                'RTSP connection confirmed from this host.',
                $preview['message'],
                $successfulSource['transport'],
                true,
                'direct',
            );
        }

        foreach ($this->relaySources($camera, $profile, $profileIndex) as $relaySource) {
            $relayProbe = $this->runProbe($ffprobeBinary, $relaySource['uri'], $relaySource['transport'], $timeoutSeconds);

            if ($relayProbe['successful']) {
                $relayPreview = $this->capturePreview(
                    $ffmpegBinary,
                    $relaySource['uri'],
                    $relaySource['transport'],
                    $absolutePreviewPath,
                    $previewPath,
                    $storage,
                    $timeoutSeconds,
                );

                return $this->successfulProfile(
                    $profile,
                    $relaySource['preserves_video']
                        ? $relayProbe['stream']
                        : $this->savedSourceStreamInfo($profile),
                    $probeCheckedAt,
                    $previewPath,
                    $storage,
                    'Direct RTSP playback was blocked by the camera, so stream verification used the active shared relay instead.',
                    $relayPreview['successful']
                        ? 'Preview captured successfully through the active shared relay because the camera rejected an additional direct session.'
                        : $relayPreview['message'],
                    $relaySource['transport'],
                    false,
                    'relay',
                );
            }

            $fallbackFailures[] = [
                'label' => $relaySource['label'],
                'message' => $relayProbe['message'],
            ];
        }

        $bufferedSource = $this->motionRecorderBufferedSource($camera, $profileIndex);

        if ($bufferedSource !== null) {
            $bufferedProbe = $this->runFileProbe($ffprobeBinary, $bufferedSource['path'], $timeoutSeconds);

            if ($bufferedProbe['successful']) {
                $bufferedPreview = $this->capturePreview(
                    $ffmpegBinary,
                    $bufferedSource['path'],
                    null,
                    $absolutePreviewPath,
                    $previewPath,
                    $storage,
                    $timeoutSeconds,
                );

                return $this->successfulProfile(
                    $profile,
                    $bufferedProbe['stream'],
                    $probeCheckedAt,
                    $previewPath,
                    $storage,
                    'Direct RTSP playback was blocked by the camera, so stream verification used the active motion recorder buffer for this same profile instead.',
                    $bufferedPreview['successful']
                        ? 'Preview captured successfully from the local motion recorder buffer because the camera rejected an additional direct session.'
                        : $bufferedPreview['message'],
                    $transport,
                    false,
                    'motion-buffer',
                );
            }

            $fallbackFailures[] = [
                'label' => $bufferedSource['label'],
                'message' => $bufferedProbe['message'],
            ];
        }

        return array_merge($profile, [
            'probe_status' => 'Failed',
            'probe_checked_at' => $probeCheckedAt,
            'probe_message' => $this->summarizeFailures($directFailures, $fallbackFailures),
        ]);
    }

    /**
     * @return array{codec_name: string|null, resolution: string|null}
     */
    private function parseProbeOutput(string $output): array
    {
        $decoded = json_decode($output, true);
        $stream = is_array($decoded) && isset($decoded['streams'][0]) && is_array($decoded['streams'][0])
            ? $decoded['streams'][0]
            : [];
        $width = isset($stream['width']) ? (string) $stream['width'] : null;
        $height = isset($stream['height']) ? (string) $stream['height'] : null;

        return [
            'codec_name' => $this->stringOrNull($stream['codec_name'] ?? null),
            'resolution' => $width !== null && $height !== null ? $width.'x'.$height : null,
            'has_b_frames' => isset($stream['has_b_frames']) ? max(0, (int) $stream['has_b_frames']) : null,
        ];
    }

    private function savedSourceStreamInfo(array $profile): array
    {
        // A live relay can be HEVC -> H264 or remove B-frames. Its probe describes
        // playback, not the camera. Saving it as source metadata can disable the
        // very transcoder that made the fallback playable.
        $codec = strtolower(trim((string) ($profile['video_codec'] ?? $profile['encoding'] ?? '')));

        return [
            'codec_name' => in_array($codec, ['h264', 'h.264', 'h265', 'h.265', 'hevc', 'mjpeg', 'jpeg', 'mpeg4'], true) ? $codec : null,
            'resolution' => $this->stringOrNull($profile['video_resolution'] ?? $profile['resolution'] ?? null),
            'has_b_frames' => $profile['video_has_b_frames'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function buildPreviewRelativePath(Camera $camera, array $profile, int $profileIndex): string
    {
        $baseName = $this->stringOrNull($profile['name'] ?? null)
            ?? $this->stringOrNull($profile['token'] ?? null)
            ?? 'profile-'.($profileIndex + 1);

        return app(CameraStorageService::class)->previewRelativePath(
            $camera,
            Str::slug($baseName).'-'.($profileIndex + 1).'.jpg',
        );
    }

    private function temporaryPreviewPath(string $absolutePreviewPath): string
    {
        return dirname($absolutePreviewPath).'/.preview-'.Str::uuid()->toString().'.jpg';
    }

    /**
     * @return array<int, array{label: string, uri: string, transport: string}>
     */
    private function directSources(string $preferredTransport, string $authenticatedUri): array
    {
        $sources = [[
            'label' => strtoupper($preferredTransport),
            'uri' => $authenticatedUri,
            'transport' => $preferredTransport,
        ]];

        if ($preferredTransport === 'udp') {
            $sources[] = [
                'label' => 'TCP',
                'uri' => $authenticatedUri,
                'transport' => 'tcp',
            ];
        }

        return $sources;
    }

    /**
     * @return array{successful: bool, stream: array{codec_name: string|null, resolution: string|null}, message: string}
     */
    private function runProbe(string $ffprobeBinary, string $uri, string $transport, int $timeoutSeconds): array
    {
        $probeProcess = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-rtsp_transport',
            $transport,
            '-timeout',
            (string) config('ffmpeg.streaming.rw_timeout', 10000000),
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=codec_name,width,height,avg_frame_rate,has_b_frames',
            '-of',
            'json',
            $uri,
        ]);
        $probeProcess->setTimeout($timeoutSeconds);

        try {
            $probeProcess->run();
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'stream' => ['codec_name' => null, 'resolution' => null],
                'message' => $this->summarizeThrowableFailure($exception),
            ];
        }

        if (! $probeProcess->isSuccessful()) {
            return [
                'successful' => false,
                'stream' => ['codec_name' => null, 'resolution' => null],
                'message' => $this->summarizeProcessFailure($probeProcess),
            ];
        }

        return [
            'successful' => true,
            'stream' => $this->parseProbeOutput($probeProcess->getOutput()),
            'message' => '',
        ];
    }

    /**
     * @return array{successful: bool, stream: array{codec_name: string|null, resolution: string|null}, message: string}
     */
    private function runFileProbe(string $ffprobeBinary, string $path, int $timeoutSeconds): array
    {
        $probeProcess = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=codec_name,width,height,avg_frame_rate,has_b_frames',
            '-of',
            'json',
            $path,
        ]);
        $probeProcess->setTimeout($timeoutSeconds);

        try {
            $probeProcess->run();
        } catch (Throwable $exception) {
            return [
                'successful' => false,
                'stream' => ['codec_name' => null, 'resolution' => null],
                'message' => $this->summarizeThrowableFailure($exception),
            ];
        }

        if (! $probeProcess->isSuccessful()) {
            return [
                'successful' => false,
                'stream' => ['codec_name' => null, 'resolution' => null],
                'message' => $this->summarizeProcessFailure($probeProcess),
            ];
        }

        return [
            'successful' => true,
            'stream' => $this->parseProbeOutput($probeProcess->getOutput()),
            'message' => '',
        ];
    }

    /**
     * @return array{successful: bool, message: string}
     */
    private function capturePreview(
        string $ffmpegBinary,
        string $uri,
        ?string $transport,
        string $absolutePreviewPath,
        string $previewPath,
        CameraStorageService $storage,
        int $timeoutSeconds,
    ): array {
        $temporaryPreviewPath = $this->temporaryPreviewPath($absolutePreviewPath);

        $command = [
            $ffmpegBinary,
            '-nostdin',
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
        ];

        if ($transport !== null) {
            $command[] = '-rtsp_transport';
            $command[] = $transport;
            $command[] = '-timeout';
            $command[] = (string) config('ffmpeg.streaming.rw_timeout', 10000000);
        }

        array_push(
            $command,
            '-i',
            $uri,
            '-frames:v',
            '1',
            '-q:v',
            '2',
            $temporaryPreviewPath,
        );

        $previewProcess = new Process($command);
        $previewProcess->setTimeout($timeoutSeconds);

        try {
            $previewProcess->run();
        } catch (Throwable $exception) {
            @unlink($temporaryPreviewPath);

            return [
                'successful' => false,
                'message' => $this->summarizeThrowableFailure($exception, 'Connected to the stream, but snapshot capture failed.'),
            ];
        }

        if (! $previewProcess->isSuccessful() || ! is_file($temporaryPreviewPath)) {
            @unlink($temporaryPreviewPath);

            return [
                'successful' => false,
                'message' => $this->summarizeProcessFailure($previewProcess, 'Connected to the stream, but snapshot capture failed.'),
            ];
        }

        try {
            $this->promotePreviewCapture($temporaryPreviewPath, $absolutePreviewPath);
            $storage->finalizeStagedWrite($previewPath, $absolutePreviewPath);

            return [
                'successful' => true,
                'message' => 'Snapshot captured successfully.',
            ];
        } catch (RuntimeException $exception) {
            return [
                'successful' => false,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array{codec_name: string|null, resolution: string|null}  $streamInfo
     * @return array<string, mixed>
     */
    private function successfulProfile(
        array $profile,
        array $streamInfo,
        string $probeCheckedAt,
        string $previewPath,
        CameraStorageService $storage,
        string $probeMessage,
        string $previewMessage,
        string $transport,
        bool $transportPersistable,
        string $probeSource,
    ): array {
        return array_merge($profile, [
            'probe_status' => 'Healthy',
            'probe_checked_at' => $probeCheckedAt,
            'probe_message' => $probeMessage,
            'video_codec' => $streamInfo['codec_name'],
            'video_resolution' => $streamInfo['resolution'],
            'video_has_b_frames' => $streamInfo['has_b_frames'] ?? null,
            'preview_path' => $storage->privateFileExists($previewPath) ? $previewPath : ($profile['preview_path'] ?? null),
            'preview_generated_at' => $storage->privateFileExists($previewPath) ? $probeCheckedAt : ($profile['preview_generated_at'] ?? null),
            'preview_message' => $previewMessage,
            'probe_source' => $probeSource,
            'transport' => strtoupper($transport),
            'transport_persistable' => $transportPersistable,
        ]);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array<int, array{label: string, uri: string, transport: string}>
     */
    private function relaySources(Camera $camera, array $profile, int $profileIndex): array
    {
        $readerUser = trim((string) config('mediamtx.auth.reader_user', ''));
        $readerPass = trim((string) config('mediamtx.auth.reader_pass', ''));
        $internalBaseUrl = rtrim((string) config('mediamtx.rtsp.internal_base_url', ''), '/');

        if ($readerUser === '' || $readerPass === '' || $internalBaseUrl === '') {
            return [];
        }

        $definitions = [];

        $liveDefinition = $this->relayConfig->cameraRelayDefinition($camera);

        if ($liveDefinition !== null && $this->relayMatchesProfile($liveDefinition, $profile, $profileIndex)) {
            $definitions[] = [
                'label' => 'Live relay',
                'path' => $liveDefinition['path'],
                'transport' => 'tcp',
                'preserves_video' => false,
            ];
        }

        $recordingDefinition = $this->relayConfig->cameraSourceRelayDefinition($camera, $profileIndex);

        if ($recordingDefinition !== null && $this->relayMatchesProfile($recordingDefinition, $profile, $profileIndex)) {
            $definitions[] = [
                'label' => 'Buffered source relay',
                'path' => $recordingDefinition['path'],
                'transport' => 'tcp',
                'preserves_video' => true,
            ];
        }

        if ($definitions === []) {
            return [];
        }

        $activePaths = $this->pathStatusService->activePaths();

        usort($definitions, function (array $left, array $right) use ($activePaths): int {
            return [isset($activePaths[$right['path']]) ? 1 : 0, $right['label']] <=> [isset($activePaths[$left['path']]) ? 1 : 0, $left['label']];
        });

        $sources = [];

        foreach ($definitions as $definition) {
            $sources[] = [
                'label' => $definition['label'],
                'uri' => $this->injectCredentials($internalBaseUrl.'/'.$definition['path'], $readerUser, $readerPass),
                'transport' => $definition['transport'],
                'preserves_video' => $definition['preserves_video'],
            ];
        }

        return array_values(array_unique($sources, SORT_REGULAR));
    }

    /**
     * @return array{label: string, path: string}|null
     */
    private function motionRecorderBufferedSource(Camera $camera, int $profileIndex): ?array
    {
        $metaPath = storage_path('app/private/motion-recorders/camera-'.(int) $camera->getKey().'.json');

        if (! is_file($metaPath)) {
            return null;
        }

        $decoded = json_decode((string) File::get($metaPath), true);

        if (! is_array($decoded) || ! array_key_exists('source_index', $decoded)) {
            return null;
        }

        $sourceIndex = is_numeric($decoded['source_index']) ? (int) $decoded['source_index'] : null;

        if ($sourceIndex !== $profileIndex) {
            return null;
        }

        $segmentDirectory = storage_path('app/private/motion-recorders/camera-'.(int) $camera->getKey().'/segments');
        $segments = File::glob($segmentDirectory.'/*-buffer.*');

        if (! is_array($segments) || $segments === []) {
            return null;
        }

        rsort($segments, SORT_STRING);

        foreach ($segments as $segmentPath) {
            if (is_string($segmentPath) && is_file($segmentPath) && filesize($segmentPath) > 0) {
                return [
                    'label' => 'Motion recorder buffer',
                    'path' => $segmentPath,
                ];
            }
        }

        return null;
    }

    /**
     * @param  array{mode: 'live', path: string, index: int|null, profile: array<string, string|null>, authenticated_uri: string, transport: string}  $definition
     * @param  array<string, mixed>  $profile
     */
    private function relayMatchesProfile(array $definition, array $profile, int $profileIndex): bool
    {
        if (($definition['index'] ?? null) === $profileIndex) {
            return true;
        }

        return $this->stringOrNull($definition['profile']['uri'] ?? null) !== null
            && $this->stringOrNull($definition['profile']['uri'] ?? null) === $this->stringOrNull($profile['uri'] ?? null);
    }

    /**
     * @param  array<int, array{label: string, message: string}>  $failures
     */
    private function summarizeFailures(array $directFailures, array $fallbackFailures = []): string
    {
        if ($directFailures !== []) {
            $message = $this->summarizeFailureList($directFailures);

            if ($fallbackFailures !== []) {
                $message .= ' No alternate internal fallback path was available either.';
            }

            return $this->appendFailureHint($message);
        }

        if ($fallbackFailures !== []) {
            return $this->appendFailureHint($this->summarizeFailureList($fallbackFailures));
        }

        if ($directFailures === []) {
            return 'Unable to inspect the RTSP stream.';
        }

        return $this->appendFailureHint($this->summarizeFailureList($directFailures));
    }

    /**
     * @param  array<int, array{label: string, message: string}>  $failures
     */
    private function summarizeFailureList(array $failures): string
    {
        return collect($failures)
            ->map(function (array $failure): string {
                $label = trim((string) ($failure['label'] ?? ''));
                $summary = trim((string) ($failure['message'] ?? 'Unable to inspect the RTSP stream.'));

                return $label !== '' ? $label.' transport failed: '.$summary : $summary;
            })
            ->implode(' ');
    }

    private function appendFailureHint(string $message): string
    {
        $normalized = Str::lower($message);

        if (str_contains($normalized, '406')) {
            return Str::limit($message.' The camera rejected DESCRIBE for this profile. Check the stream path and profile compatibility.', 260);
        }

        if (str_contains($normalized, 'operation not permitted')) {
            return Str::limit($message.' The camera refused playback after RTSP setup. Check stream permissions and active session limits.', 260);
        }

        return Str::limit($message, 260);
    }

    private function summarizeThrowableFailure(Throwable $exception, string $fallback = 'Unable to inspect the RTSP stream.'): string
    {
        $message = $this->sanitizeDiagnosticMessage(trim($exception->getMessage()));

        if ($message !== '') {
            $normalized = Str::lower($message);

            if (str_contains($normalized, 'signal "11"') || str_contains($normalized, 'signal 11')) {
                return 'The stream probe process crashed while inspecting this source.';
            }
        }

        return $message !== '' ? Str::limit($message, 220) : $fallback;
    }

    private function promotePreviewCapture(string $temporaryPreviewPath, string $absolutePreviewPath): void
    {
        if (! is_file($temporaryPreviewPath)) {
            throw new RuntimeException('Connected to the stream, but snapshot capture failed.');
        }

        if (! @rename($temporaryPreviewPath, $absolutePreviewPath)) {
            if (is_file($absolutePreviewPath)) {
                @unlink($absolutePreviewPath);
            }

            if (! @rename($temporaryPreviewPath, $absolutePreviewPath)) {
                $error = error_get_last();
                @unlink($temporaryPreviewPath);

                throw new RuntimeException(
                    'Connected to the stream, but snapshot capture could not replace the saved thumbnail'.
                    (is_array($error) && is_string($error['message'] ?? null) ? ': '.trim($error['message']) : '.')
                );
            }
        }

        @chmod($absolutePreviewPath, 0664);
    }

    private function injectCredentials(string $uri, ?string $username, ?string $password): string
    {
        CameraUrl::assertRtsp($uri);
        $parts = parse_url($uri);

        if (! is_array($parts) || isset($parts['user']) || $username === null || $username === '' || $password === null || $password === '') {
            return $uri;
        }

        $authority = rawurlencode($username).':'.rawurlencode($password).'@'.$parts['host'];

        if (isset($parts['port'])) {
            $authority .= ':'.$parts['port'];
        }

        return ($parts['scheme'] ?? 'rtsp').'://'.$authority.($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function summarizeProcessFailure(Process $process, string $fallback = 'Unable to inspect the RTSP stream.'): string
    {
        $message = trim($process->getErrorOutput() ?: $process->getOutput());

        if ($message === '' && $process->hasBeenSignaled()) {
            $signal = $process->getTermSignal();
            $message = $signal !== null
                ? 'The stream probe process exited unexpectedly with signal '.$signal.'.'
                : 'The stream probe process exited unexpectedly.';
        }

        if ($message === '') {
            return $fallback;
        }

        $normalized = preg_replace('/\s+/', ' ', $message) ?? $message;

        return Str::limit($this->sanitizeDiagnosticMessage($normalized), 220);
    }

    private function sanitizeDiagnosticMessage(string $message): string
    {
        return preg_replace(
            '#\b([a-z][a-z0-9+\-.]*://)(?:[^/\s:@]+(?::[^/\s@]*)?@)#i',
            '$1',
            $message,
        ) ?? $message;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
