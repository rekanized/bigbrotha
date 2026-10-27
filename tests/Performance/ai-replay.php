<?php

use App\Models\Camera;
use App\Models\CameraAnalysisEvent;
use App\Services\Analysis\AnalysisCoordinator;
use App\Services\Analysis\AnalysisLabels;
use App\Services\Analysis\AnalysisSampler;
use App\Services\CameraRecordingService;
use App\Services\RecordingMotionDetectorService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Isolated full-pipeline replay. Never reads camera inventory or writes deployment databases.
// AI_URL points to an isolated inference service; AI_TOKEN is a fictional benchmark token.
$clip = $argv[1] ?? '';
$cameras = max(1, min(8, (int) ($argv[2] ?? 1)));
$duration = max(5, min(600, (int) ($argv[3] ?? 20)));
$mode = $argv[4] ?? 'full';
if (! is_file($clip) || ! in_array($mode, ['full', 'motion', 'baseline'], true)) {
    fwrite(STDERR, "Usage: php tests/Performance/ai-replay.php clip cameras seconds [full|motion|baseline]\n");
    exit(1);
}
if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing replay with cached deployment configuration.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32))] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
try {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->useStoragePath(sys_get_temp_dir().'/bigbrotha-ai-replay-'.getmypid());
    @mkdir(storage_path('framework/cache/data'), 0770, true);
    @mkdir(storage_path('framework/views'), 0770, true);
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null,
        'cache.default' => 'array', 'analysis.enabled' => true, 'analysis.token' => getenv('AI_TOKEN') ?: str_repeat('test', 8),
        'analysis.url' => getenv('AI_URL') ?: 'http://127.0.0.1:8091', 'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
        'recording.motion.pixel_delta_threshold' => 18]);
    DB::purge();
    config(['cache.stores.file.path' => storage_path('framework/cache/data')]);
    Artisan::call('migrate', ['--force' => true]);
    for ($index = 0; $index < $cameras; $index++) {
        Camera::create(['name' => 'Replay '.$index, 'local_ip' => '192.0.2.'.($index + 1),
            'supports_rtsp' => true, 'is_enabled' => true, 'rtsp_path' => '/fixture', 'recording_mode' => 'continuous',
            'ai_enabled' => true, 'recording_motion_trigger_pixels' => 10, 'recording_retention_days' => 1]);
    }
    $coordinator = new class(app(CameraRecordingService::class), app(RecordingMotionDetectorService::class), app(AnalysisLabels::class)) extends AnalysisCoordinator
    {
        public string $clip;

        protected function createSampler(string $binary, Camera $camera, array $source): AnalysisSampler
        {
            return new AnalysisSampler($binary, $camera, [], ['-re', '-stream_loop', '-1', '-i', $this->clip]);
        }
    };
    $coordinator->clip = realpath($clip);
    $coordinator->captureResults = true;
    $start = microtime(true);

    $processes = [];
    for ($index = 0; $index < $cameras; $index++) {
        $processes[] = proc_open(['/usr/bin/ffmpeg', '-nostdin', '-loglevel', 'error', '-re', '-stream_loop', '-1', '-i', realpath($clip), '-t', (string) $duration, '-c', 'copy', '-f', 'null', '-'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    }

    if ($mode !== 'baseline') {
        if ($mode === 'motion') {
            $coordinator->inferenceEnabled = false;
        }
        $coordinator->run($duration);
    }
    foreach ($processes as $process) {
        if (! is_resource($process) || proc_close($process) !== 0) {
            throw new RuntimeException('Stream-copy replay failed.');
        }
    }
    $cpu = function (array $r): float {
        return ($r['ru_utime.tv_sec'] ?? 0) + ($r['ru_stime.tv_sec'] ?? 0) + (($r['ru_utime.tv_usec'] ?? 0) + ($r['ru_stime.tv_usec'] ?? 0)) / 1000000;
    };
    $events = CameraAnalysisEvent::query()->get();
    echo json_encode(['mode' => $mode, 'cameras' => $cameras, 'wall_seconds' => microtime(true) - $start,
        'coordinator_cpu_seconds' => $cpu(getrusage()), 'ffmpeg_cpu_seconds' => $cpu(getrusage(1)),
        'coordinator_peak_rss_kib' => getrusage()['ru_maxrss'], 'max_child_peak_rss_kib' => getrusage(1)['ru_maxrss'],
        'analyzed_frames' => $events->sum('analyzed_frames'), 'skipped_frames' => $events->sum('skipped_frames'),
        ...$coordinator->metrics], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    $failed = true;
} finally {
    foreach ($processes ?? [] as $process) {
        if (is_resource($process)) {
            proc_terminate($process, 9);
            proc_close($process);
        }
    }
    if (isset($app)) {
        File::deleteDirectory(storage_path());
    }
}

exit(isset($failed) ? 1 : 0);
