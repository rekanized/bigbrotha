<?php

namespace Tests\Feature;

use App\Services\RuntimeHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RecordingContainerHealthCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_healthcheck_passes_with_a_fresh_heartbeat(): void
    {
        $this->writeBootstrapMarker();
        $this->configureWorkerProcessSnapshot();
        app(RuntimeHeartbeatService::class)->touchWorker('test');

        $exitCode = Artisan::call('camera-recordings:healthcheck', ['role' => 'worker']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Worker container health checks passed.', Artisan::output());
    }

    public function test_worker_healthcheck_fails_when_the_worker_heartbeat_is_stale(): void
    {
        $this->writeBootstrapMarker();
        $this->configureWorkerProcessSnapshot();
        config()->set('recording.health.worker_max_age_seconds', 60);

        $path = app(RuntimeHeartbeatService::class)->workerPath();
        File::put($path, json_encode([
            'updated_at' => now()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        touch($path, now()->utc()->subMinutes(15)->getTimestamp());

        $exitCode = Artisan::call('camera-recordings:healthcheck', ['role' => 'worker']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Worker heartbeat is stale', Artisan::output());
    }

    public function test_worker_healthcheck_fails_when_recordings_jobs_age_out_in_the_queue(): void
    {
        $this->writeBootstrapMarker();
        $this->configureWorkerProcessSnapshot();
        config()->set('recording.health.worker_max_queued_age_seconds', 60);
        app(RuntimeHeartbeatService::class)->touchWorker('test');

        DB::table('jobs')->insert([
            'queue' => config('recording.queue', 'recordings'),
            'payload' => json_encode(['displayName' => 'TestJob'], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->utc()->subMinutes(10)->timestamp,
            'created_at' => now()->utc()->subMinutes(10)->timestamp,
        ]);

        $exitCode = Artisan::call('camera-recordings:healthcheck', ['role' => 'worker']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Oldest queued recordings job is', Artisan::output());
    }

    public function test_worker_healthcheck_passes_from_a_non_worker_container_when_shared_worker_heartbeat_exists(): void
    {
        $this->writeBootstrapMarker();
        $this->configureWorkerProcessSnapshot(empty: true);
        config()->set('recording.worker.container_mode', true);

        $heartbeatDirectory = dirname((string) config('recording.health.worker_heartbeat_path'));
        File::ensureDirectoryExists($heartbeatDirectory);
        File::put(
            $heartbeatDirectory.'/recordings-worker-worker-a.heartbeat',
            json_encode(['updated_at' => now()->utc()->toIso8601String()], JSON_THROW_ON_ERROR)
        );

        $exitCode = Artisan::call('camera-recordings:healthcheck', ['role' => 'worker']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Worker container health checks passed.', Artisan::output());
    }

    public function test_scheduler_healthcheck_fails_when_the_scheduler_heartbeat_is_stale(): void
    {
        $this->writeBootstrapMarker();
        config()->set('recording.health.scheduler_max_age_seconds', 60);
        app(RuntimeHeartbeatService::class)->touchRecordingTick('test');

        $path = app(RuntimeHeartbeatService::class)->schedulerPath();
        File::put($path, json_encode([
            'updated_at' => now()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        touch($path, now()->utc()->subMinutes(10)->getTimestamp());

        $exitCode = Artisan::call('camera-recordings:healthcheck', ['role' => 'scheduler']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Scheduler heartbeat is stale', Artisan::output());
    }

    public function test_scheduler_healthcheck_fails_when_the_recording_tick_heartbeat_is_stale(): void
    {
        $this->writeBootstrapMarker();
        config()->set('recording.health.scheduler_tick_max_age_seconds', 60);
        app(RuntimeHeartbeatService::class)->touchScheduler('test');

        $path = app(RuntimeHeartbeatService::class)->recordingTickPath();
        File::put($path, json_encode([
            'updated_at' => now()->utc()->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
        touch($path, now()->utc()->subMinutes(10)->getTimestamp());

        $exitCode = Artisan::call('camera-recordings:healthcheck', ['role' => 'scheduler']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Recording tick heartbeat is stale', Artisan::output());
    }

    private function writeBootstrapMarker(): void
    {
        $path = storage_path('app/private/bootstrap/app.ready');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, Carbon::now()->utc()->toIso8601String());
    }

    private function configureWorkerProcessSnapshot(bool $empty = false): void
    {
        $psBinary = storage_path('app/private/test-binaries/recording-health-ps.sh');
        File::ensureDirectoryExists(dirname($psBinary));

        if ($empty) {
            File::put($psBinary, <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);
        } else {
            File::put($psBinary, sprintf(
                "#!/usr/bin/env bash\ncat <<'OUT'\n %d /usr/bin/php artisan queue:work --queue=recordings,default,review-assets --max-jobs=50 --max-time=3600 --memory=256\nOUT\n",
                getmypid(),
            ));
        }

        chmod($psBinary, 0755);

        config()->set('recording.worker.container_mode', false);
        config()->set('recording.worker.ps_binary', $psBinary);
    }
}