<?php

// Manual concurrency regression for a disposable PostgreSQL container only.
// Run on the isolated bigbrotha-ai-review Docker network with host alias ai-review-db.
// The database name/host/credentials are fixed fixtures, never deployment settings.
use App\Models\Camera;
use App\Models\CameraAnalysisEvent;
use App\Models\CameraAnalysisLabel;
use App\Models\CameraRecording;
use App\Services\Analysis\AnalysisLabels;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

if (is_file(__DIR__.'/../../bootstrap/cache/config.php')) {
    throw new RuntimeException('Refusing cached deployment configuration.');
}
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'ai-review-db', 'DB_PORT' => '5432',
    'DB_DATABASE' => 'ai_review', 'DB_USERNAME' => 'fixture', 'DB_PASSWORD' => 'fixture-only', 'DB_URL' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32))] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
try {
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->useStoragePath(sys_get_temp_dir().'/bigbrotha-ai-metadata-'.getmypid());
    @mkdir(storage_path('framework/cache/data'), 0770, true);
    @mkdir(storage_path('framework/views'), 0770, true);
    $app->make(Kernel::class)->bootstrap();
    config(['cache.stores.file.path' => storage_path('framework/cache/data')]);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $camera = Camera::create(['name' => 'Race fixture', 'local_ip' => '192.0.2.10', 'recording_retention_days' => 2]);
    $at = microtime(true) - 10;
    $start = Carbon::createFromTimestampUTC($at - 2);
    $end = Carbon::createFromTimestampUTC($at + 2);
    $event = CameraAnalysisEvent::create(['camera_id' => $camera->id, 'generation' => str_repeat('a', 64), 'started_at' => $start]);
    $recording = CameraRecording::create(['camera_id' => $camera->id, 'capture_mode' => 'continuous', 'status' => 'recorded',
        'scheduled_for' => $start, 'started_at' => $start, 'ended_at' => $end]);
    (new AnalysisLabels)->record($event, [['class' => 'person', 'confidence' => .7, 'times' => [$at]]]);
    $labelId = $event->labels()->first()->id;
    DB::disconnect(); // Never share an inherited PDO socket between forked workers.
    $children = [];
    for ($worker = 0; $worker < 3; $worker++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork metadata worker.');
        }
        if ($pid === 0) {
            try {
                $labels = new AnalysisLabels;
                for ($step = 0; $step < 40; $step++) {
                    if ($worker === 0) {
                        $labels->record($event, [['class' => 'person', 'confidence' => .9, 'times' => [$at + $step / 100]]]);
                    } elseif ($worker === 1) {
                        $labels->associate(CameraAnalysisLabel::findOrFail($labelId));
                    } else {
                        if ($step === 10) {
                            CameraRecording::findOrFail($recording->id)->delete();
                            CameraRecording::create(['camera_id' => $camera->id, 'capture_mode' => 'continuous', 'status' => 'recorded',
                                'scheduled_for' => $start, 'started_at' => $start, 'ended_at' => $end]);
                        }
                        $labels->maintain();
                    }
                    usleep(2000);
                }
                DB::disconnect();
                exit(0);
            } catch (Throwable $exception) {
                fwrite(STDERR, $exception->getMessage().PHP_EOL);
                exit(1);
            }
        }
        $children[] = $pid;
    }
    $failed = false;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $failed = $failed || ! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0;
    }
    try {
        (new AnalysisLabels)->maintain();
        if ($failed || CameraAnalysisLabel::count() !== 1 || DB::table('camera_analysis_label_recording')->count() !== 1
            || CameraAnalysisLabel::first()->recordings()->first()?->id === $recording->id
            || count(CameraAnalysisLabel::first()->observed_at) !== 40) {
            throw new RuntimeException('Concurrent association/retention regression failed.');
        }
        echo "PostgreSQL concurrent writes, duplicate association, retention and late finalization passed.\n";
    } finally {
        DB::disconnect();
        File::deleteDirectory(storage_path());
    }

} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage().PHP_EOL);
    exit(1);
}
