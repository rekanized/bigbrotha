<?php

namespace App\Services\Analysis;

use App\Models\Camera;
use App\Models\CameraAnalysisEvent;
use App\Services\CameraRecordingService;
use App\Services\RecordingMotionDetectorService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AnalysisCoordinator
{
    private array $states = [];

    private bool $stop = false;

    private mixed $multi;

    private ?array $flight = null;

    private int $lastCamera = 0;

    private float $leaseAt = 0;

    public function __construct(private CameraRecordingService $recordings, private RecordingMotionDetectorService $motion, private AnalysisLabels $labels) {}

    public static function generation(Camera $camera): string
    {
        return hash('sha256', json_encode([$camera->only(['id', 'is_enabled', 'supports_rtsp', 'ai_enabled', 'ai_profile_index', 'recording_mode', 'recording_profile_index', 'recording_rtsp_path', 'rtsp_path', 'local_ip', 'hostname', 'rtsp_port', 'rtsp_transport', 'username', 'recording_motion_mask', 'recording_motion_area', 'recording_motion_trigger_pixels', 'motion_sensitivity']), $camera->getRawOriginal('password'), array_map(fn ($profile) => $profile['uri'] ?? '', $camera->rtspProfiles()), config('recording.motion')], JSON_THROW_ON_ERROR));
    }

    public array $metrics = ['frames' => 0, 'requests' => 0, 'results' => []];

    public bool $captureResults = false;

    public bool $inferenceEnabled = true;

    protected function createSampler(string $binary, Camera $camera, array $source): AnalysisSampler
    {
        return new AnalysisSampler($binary, $camera, $source);
    }

    public function run(?int $durationSeconds = null): void
    {
        $this->multi = curl_multi_init();
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop = true);
        pcntl_signal(SIGINT, fn () => $this->stop = true);
        $refreshAt = 0;
        $startedAt = microtime(true);
        try {
            while (! $this->stop && ($durationSeconds === null || microtime(true) - $startedAt < $durationSeconds)) {
                $now = microtime(true);
                if ($now >= $refreshAt) {
                    $this->refresh();
                    $refreshAt = $now + 2;
                }
                foreach ($this->states as $id => &$state) {
                    try {
                        if ($state['sampler'] === null && $now >= $state['retry_at']) {
                            $source = $this->recordings->resolveBufferedRecordingSource($state['camera'], $state['camera']->ai_profile_index)
                                ?? $this->recordings->resolveRecordingSource($state['camera'], $state['camera']->ai_profile_index);
                            if ($source === null || ! $binary = $this->recordings->ffmpegBinary()) {
                                throw new \RuntimeException('Analysis source unavailable.');
                            }
                            $state['sampler'] = $this->createSampler($binary, $state['camera'], $source);
                        }
                        if ($state['sampler'] !== null) {
                            foreach ($state['sampler']->read() as $frame) {
                                $state['stream_ready'] = true;
                                $this->metrics['frames']++;
                                $this->frame($state, $frame);
                                $state['backoff'] = 1;
                            }
                        }
                        if ($state['event'] !== null && $now - $state['last_motion'] > 3) {
                            $this->finish($state);
                        }
                    } catch (Throwable) {
                        $state['sampler']?->close();
                        $state['sampler'] = null;
                        $state['ring'] = [];
                        $state['previous'] = [];
                        $state['stream_ready'] = false;
                        $state['retry_at'] = $now + $state['backoff'];
                        $state['backoff'] = min(30, $state['backoff'] * 2);
                        $state['event']?->increment('skipped_frames');
                        $this->finish($state);
                    }
                    if ($now >= $state['status_at']) {
                        Cache::store('file')->put('analysis:'.$id, ['state' => $this->status($state), 'active' => $state['event'] !== null,
                            'labels' => $state['confirmed'], 'provider' => $state['provider'], 'fallback' => $state['fallback'], 'updated_at' => $now], 15);
                        $state['status_at'] = $now + 2;
                    }
                }
                unset($state);
                $this->poll();
                $this->dispatch();
                usleep($this->states === [] && $this->flight === null ? 500000 : 20000);
            }
        } finally {
            // Reap every decoder before touching storage: a database/cache outage
            // must not leave orphan FFmpeg processes behind on coordinator restart.
            foreach ($this->states as &$state) {
                $state['sampler']?->close();
                $state['sampler'] = null;
            }
            unset($state);
            if ($this->flight !== null) {
                curl_multi_remove_handle($this->multi, $this->flight['handle']);
            }
            curl_multi_close($this->multi);
            foreach ($this->states as $id => &$state) {
                try {
                    Cache::store('file')->forget('analysis:'.$id);
                    $this->finish($state);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
            if (isset($this->flight['event'])) {
                try {
                    $this->flight['event']->increment('skipped_frames');
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        }
    }

    private function refresh(): void
    {
        $cameras = config('analysis.enabled') && strlen(config('analysis.token')) >= 32
            ? Camera::query()->where('ai_enabled', true)->where('is_enabled', true)->where('supports_rtsp', true)
                ->whereIn('recording_mode', [Camera::RECORDING_MODE_MOTION, Camera::RECORDING_MODE_CONTINUOUS])->get()->filter(fn (Camera $camera) => $camera->recordingMotionMask()['selected_pixels'] > 0)->keyBy('id')
            : collect();
        foreach ($this->states as $id => &$state) {
            if (! isset($cameras[$id]) || self::generation($cameras[$id]) !== $state['generation']) {
                if (($this->flight['id'] ?? null) === $id) {
                    curl_multi_remove_handle($this->multi, $this->flight['handle']);
                    $this->flight['event']->increment('skipped_frames');
                    $this->flight = null;
                }
                $this->finish($state);
                $state['sampler']?->close();
                Cache::store('file')->forget('analysis:'.$id);
                unset($this->states[$id]);
            }
        }
        unset($state);
        foreach ($cameras as $id => $camera) {
            if (isset($this->states[$id])) {
                continue;
            }
            $this->states[$id] = ['camera' => $camera, 'generation' => self::generation($camera), 'sampler' => null,
                'ring' => [], 'event' => null, 'last_motion' => 0, 'next_sample' => 0, 'samples' => 0,
                'pending' => [], 'previous' => [], 'confirmed' => [], 'provider' => null, 'fallback' => null,
                'retry_at' => 0, 'backoff' => 1, 'stream_ready' => false, 'service_ready' => null,
                'model_identity' => null, 'status_at' => 0, 'overloaded_until' => 0];
        }
    }

    private function frame(array &$state, array $frame): void
    {
        $state['ring'][] = $frame;
        $state['ring'] = array_slice($state['ring'], -7);
        if (count($state['ring']) < 3) {
            return;
        }
        $result = $this->motion->analyzeFrames($state['camera'], implode('', array_column($state['ring'], 'gray')), true, true);
        // The detector confirms the penultimate transition using the newest frame as lookahead.
        $sample = $state['ring'][count($state['ring']) - 2];
        $now = $sample['at'];
        if ($result['detected']) {
            $state['last_motion'] = $frame['at'];
            if ($state['event'] === null || $now - $state['event']->started_at->getTimestamp() > 60) {
                $this->finish($state);
                $state['event'] = CameraAnalysisEvent::create(['camera_id' => $state['camera']->id, 'generation' => $state['generation'],
                    'started_at' => Carbon::createFromTimestampUTC($now)]);
                $state['samples'] = 0;
                $state['next_sample'] = 0;
                $state['confirmed'] = [];
            }
        }
        if ($state['event'] !== null && $now >= $state['next_sample']) {
            $key = $state['samples'] === 0 ? 'initial' : 'latest';
            if (isset($state['pending'][$key])) {
                $state['event']->increment('skipped_frames');
                $state['overloaded_until'] = microtime(true) + 5;
            }
            $state['pending'][$key] = ['frame' => $sample['frame'], 'at' => $now, 'event' => $state['event']];
            $state['samples']++;
            // Advance the deadline instead of drifting to 1.33s when a 3fps sample
            // arrives a few milliseconds before the one-second boundary.
            $state['next_sample'] = $state['samples'] === 1 ? $now + 0.5 : max($state['next_sample'] + 1, $now + 0.5);
        }
    }

    private function finish(array &$state): void
    {
        if ($state['event'] !== null) {
            $state['event']->increment('skipped_frames', count($state['pending']));
            $state['event']->update(['ended_at' => now()]);
        }
        $state['event'] = null;
        $state['pending'] = [];
        $state['previous'] = [];
        $state['confirmed'] = [];
        $state['overloaded_until'] = 0;
    }

    private function dispatch(): void
    {
        if ($this->flight !== null || ! $this->inferenceEnabled) {
            return;
        }
        if (config('analysis.enabled') && strlen(config('analysis.token')) >= 32 && microtime(true) >= $this->leaseAt) {
            $handle = curl_init(rtrim(config('analysis.url'), '/').'/v1/lease');
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 500,
                CURLOPT_TIMEOUT_MS => 5000, CURLOPT_POSTFIELDS => json_encode(['enabled' => count($this->states)]),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer '.config('analysis.token'), 'Content-Type: application/json']]);
            curl_multi_add_handle($this->multi, $handle);
            $this->flight = ['lease' => true, 'handle' => $handle];
            $this->leaseAt = microtime(true) + 10;

            return;
        }
        $ids = array_keys($this->states);
        sort($ids);
        $ids = [...array_filter($ids, fn ($id) => $id > $this->lastCamera), ...array_filter($ids, fn ($id) => $id <= $this->lastCamera)];
        foreach ($ids as $id) {
            $state = &$this->states[$id];
            foreach ($state['pending'] as $key => $pending) {
                unset($state['pending'][$key]);
                if (microtime(true) - $pending['at'] > 2) {
                    $pending['event']->increment('skipped_frames');
                    $state['overloaded_until'] = microtime(true) + 5;

                    continue;
                }
                $handle = curl_init(rtrim(config('analysis.url'), '/').'/v1/analyze');
                curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 1000,
                    CURLOPT_TIMEOUT_MS => 5000, CURLOPT_POSTFIELDS => $pending['frame'],
                    CURLOPT_HTTPHEADER => ['Authorization: Bearer '.config('analysis.token'), 'Content-Type: image/x-portable-pixmap', 'Expect:',
                        'X-Camera-Id: '.$id, 'X-Captured-At: '.$pending['at'], 'X-Generation: '.$state['generation']]]);
                curl_multi_add_handle($this->multi, $handle);
                $this->flight = [...$pending, 'id' => $id, 'generation' => $state['generation'], 'previous' => $state['previous'], 'handle' => $handle];
                $this->metrics['requests']++;
                $this->lastCamera = $id;

                return;
            }
        }
    }

    private function poll(): void
    {
        // Drain ready upload sockets without imposing a loop sleep on every 64 KiB chunk.
        // Keep this bounded so camera decoding and motion checks still get serviced.
        $deadline = microtime(true) + 0.005;
        do {
            curl_multi_exec($this->multi, $running);
        } while ($running && microtime(true) < $deadline && curl_multi_select($this->multi, 0) > 0);
        while ($message = curl_multi_info_read($this->multi)) {
            $flight = $this->flight;
            $this->flight = null;
            $handle = $message['handle'];
            $response = json_decode(curl_multi_getcontent($handle), true);
            $ok = $message['result'] === CURLE_OK && curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 200;
            curl_multi_remove_handle($this->multi, $handle);
            if ($flight['lease'] ?? false) {
                $this->receiveLease($response, $ok);

                continue;
            }
            if ($flight !== null) {
                $this->receiveResult($flight, $response, $ok);
            }
        }
    }

    private function status(array $state): string
    {
        if (! $state['stream_ready']) {
            return $state['retry_at'] > 0 ? 'unavailable' : 'starting';
        }
        if ($state['service_ready'] !== true) {
            return $state['service_ready'] === false ? 'unavailable' : 'starting';
        }

        return microtime(true) < $state['overloaded_until'] ? 'overloaded' : 'available';
    }

    private function receiveLease(mixed $response, bool $ok): void
    {
        $ready = $ok && is_array($response) && ($response['loaded'] ?? false) === true
            && in_array($response['provider'] ?? null, ['CPUExecutionProvider', 'CUDAExecutionProvider'], true);
        if ($ready && $this->captureResults) {
            $this->metrics['model'] = array_intersect_key($response, array_flip(['model_version', 'model_sha256', 'thresholds', 'calibrated']));
        }
        foreach ($this->states as &$state) {
            $state['service_ready'] = $ready;
            $state['provider'] = $ready ? $response['provider'] : null;
            $state['fallback'] = $ready ? ($response['fallback'] ?? null) : null;
        }
    }

    private function receiveResult(array $flight, mixed $response, bool $ok): void
    {
        if (! isset($this->states[$flight['id']])) {
            return;
        }
        $state = &$this->states[$flight['id']];
        $freshCamera = Camera::find($flight['id']);
        if ($freshCamera === null || self::generation($freshCamera) !== $flight['generation'] || $state['generation'] !== $flight['generation']) {
            $flight['event']->increment('skipped_frames');

            return;
        }
        $event = $flight['event'];
        if (! $ok || ! is_array($response) || ($response['generation'] ?? null) !== $flight['generation']
            || ($response['camera_id'] ?? null) !== $flight['id']
            || ! is_numeric($response['captured_at'] ?? null)
            || ! is_finite((float) $response['captured_at'])
            || abs(($response['captured_at'] ?? 0) - $flight['at']) > 0.001
            || ! is_array($response['detections'] ?? null) || ! $this->labels->validDetections($response['detections'])
            || ! is_string($response['model_version'] ?? null) || $response['model_version'] === ''
            || ! is_string($response['model_sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $response['model_sha256'])) {
            $event->increment('skipped_frames');
            $state['service_ready'] = false;

            return;
        }
        $identity = hash('sha256', json_encode(array_intersect_key($response, array_flip(['model_version', 'model_sha256', 'thresholds', 'calibrated'])), JSON_THROW_ON_ERROR));
        if ($state['model_identity'] !== null && $state['model_identity'] !== $identity) {
            $event->increment('skipped_frames');
            if ($state['event']?->id === $event->id) {
                $this->finish($state);
            }
            $state['previous'] = [];
            $state['model_identity'] = $identity;

            return;
        }
        $state['model_identity'] = $identity;
        if ($this->captureResults) {
            $this->metrics['model'] = array_intersect_key($response, array_flip(['model_version', 'model_sha256', 'thresholds', 'calibrated']));
            $this->metrics['results'][] = ['camera_id' => $flight['id'], 'event_id' => $event->id, 'captured_at' => $flight['at'],
                'detections' => $response['detections'], 'inference_ms' => $response['inference_ms'] ?? null,
                'inference_return_delay_ms' => (microtime(true) - $flight['at']) * 1000];
        }
        $state['service_ready'] = true;
        $state['provider'] = $response['provider'] ?? 'CPUExecutionProvider';
        $state['fallback'] = $response['fallback'] ?? null;
        $event->increment('analyzed_frames');
        $event->update(['model_version' => $response['model_version'], 'model_sha256' => $response['model_sha256']]);
        // Responses for completed events remain useful, but cannot confirm a new event.
        $previous = $state['event']?->id === $event->id ? $state['previous'] : $flight['previous'];
        $confirmed = $this->labels->confirm($response['detections'], $flight['at'], $previous);
        $this->labels->record($event, $confirmed);
        if ($state['event']?->id === $event->id) {
            $state['previous'] = $previous;
            $state['confirmed'] = array_values(array_unique([...$state['confirmed'], ...array_column($confirmed, 'class')]));
        }
    }
}
