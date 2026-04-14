<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Services\RecordingWorkerService;
use App\Services\RuntimeHeartbeatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RecordingWorkerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_uses_the_configured_worker_process_snapshot_binary(): void
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $psBinary = $binaryDirectory.'/recording-worker-ps-running.sh';
        File::put($psBinary, sprintf(
            "#!/usr/bin/env bash\ncat <<'OUT'\n %d /usr/bin/php artisan queue:work --queue=recordings,default,review-assets --max-jobs=50 --max-time=3600 --memory=256\nOUT\n",
            getmypid(),
        ));
        chmod($psBinary, 0755);

        config()->set('recording.worker.container_mode', false);
        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.queue', 'recordings,default,review-assets');
        config()->set('recording.worker.ps_binary', $psBinary);

        $snapshot = app(RecordingWorkerService::class)->snapshot();

        $this->assertTrue($snapshot['running']);
        $this->assertSame(1, $snapshot['running_workers']);
        $this->assertSame([getmypid()], $snapshot['running_pids']);
    }

    public function test_snapshot_scales_desired_workers_from_camera_and_queue_demand(): void
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $psBinary = $binaryDirectory.'/recording-worker-ps-empty.sh';
        File::put($psBinary, <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);
        chmod($psBinary, 0755);

        foreach (range(1, 5) as $index) {
            Camera::query()->create([
                'name' => 'Snapshot Cam '.$index,
                'local_ip' => '192.168.1.'.(150 + $index),
                'rtsp_port' => 554,
                'rtsp_path' => '/stream'.$index,
                'supports_onvif' => false,
                'supports_rtsp' => true,
                'is_enabled' => true,
                'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
                'recording_retention_days' => 1,
            ]);
        }

        foreach (range(1, 240) as $index) {
            DB::table('jobs')->insert([
                'queue' => 'recordings',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\ProcessCameraRecordingJob']),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ]);
        }

        config()->set('recording.worker.container_mode', false);
        config()->set('recording.worker.ensure_running', true);
        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.dynamic_enabled', true);
        config()->set('recording.worker.max_processes', 4);
        config()->set('recording.worker.cameras_per_process', 4);
        config()->set('recording.worker.jobs_per_process', 120);
        config()->set('recording.worker.queue', 'recordings,default,review-assets');
        config()->set('recording.worker.ps_binary', $psBinary);

        $snapshot = app(RecordingWorkerService::class)->snapshot();

        $this->assertFalse($snapshot['running']);
        $this->assertSame(0, $snapshot['running_workers']);
        $this->assertSame(2, $snapshot['desired_workers']);
        $this->assertTrue($snapshot['dynamic_enabled']);
        $this->assertSame(1, $snapshot['minimum_workers']);
        $this->assertSame(4, $snapshot['maximum_workers']);
        $this->assertSame(['recordings', 'default', 'review-assets'], $snapshot['queue_names']);
        $this->assertSame(5, $snapshot['enabled_recording_cameras']);
        $this->assertSame(240, $snapshot['queued_worker_jobs']);
    }

    public function test_snapshot_uses_shared_worker_heartbeats_in_container_mode(): void
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $psBinary = $binaryDirectory.'/recording-worker-ps-container-empty.sh';
        File::put($psBinary, <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);
        chmod($psBinary, 0755);

        config()->set('recording.worker.container_mode', true);
        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.dynamic_enabled', true);
        config()->set('recording.worker.ps_binary', $psBinary);

        app(RuntimeHeartbeatService::class)->touchWorker('test');

        $snapshot = app(RecordingWorkerService::class)->snapshot();

        $this->assertTrue($snapshot['running']);
        $this->assertSame(1, $snapshot['running_workers']);
        $this->assertSame(1, $snapshot['desired_workers']);
        $this->assertSame([], $snapshot['running_pids']);
    }
}
