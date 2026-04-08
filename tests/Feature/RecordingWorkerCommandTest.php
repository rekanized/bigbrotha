<?php

namespace Tests\Feature;

use App\Models\Camera;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RecordingWorkerCommandTest extends TestCase
{
  use RefreshDatabase;

    public function test_it_installs_and_enables_the_recordings_worker_systemd_unit(): void
    {
    $binaryDirectory = storage_path('app/private/test-binaries');
    $systemdDirectory = $binaryDirectory.'/systemd-user';
    File::ensureDirectoryExists($binaryDirectory);

    $invocationLog = $binaryDirectory.'/recording-worker-install.log';
    @unlink($invocationLog);

    $systemctlBinary = $binaryDirectory.'/recording-worker-install-systemctl.sh';
    File::put($systemctlBinary, <<<BASH
#!/usr/bin/env bash
echo "$*" >> {$invocationLog}
exit 0
BASH);
    chmod($systemctlBinary, 0755);

    config()->set('recording.worker.processes', 1);
    config()->set('recording.worker.systemctl_binary', $systemctlBinary);
    config()->set('recording.worker.systemd_user_dir', $systemdDirectory);
    config()->set('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');
    config()->set('recording.worker.php_binary', '/usr/bin/php');
    config()->set('recording.worker.queue', 'recordings,default');
    config()->set('recording.worker.max_jobs', 50);
    config()->set('recording.worker.max_time', 3600);
    config()->set('recording.worker.memory', 256);

    $exitCode = Artisan::call('camera-recordings:install-worker-service');
    $output = Artisan::output();
    $servicePath = $systemdDirectory.'/bigbrothas-recordings-queue.service';

    $this->assertSame(0, $exitCode);
    $this->assertStringContainsString('Installed and started the recordings worker systemd unit.', $output);
    $this->assertFileExists($servicePath);
    $this->assertStringContainsString('WorkingDirectory='.base_path(), (string) file_get_contents($servicePath));
    $this->assertStringContainsString('ExecStart=/usr/bin/php artisan queue:work --queue=recordings,default --max-jobs=50 --max-time=3600 --memory=256', (string) file_get_contents($servicePath));
    $this->assertFileExists($invocationLog);
    $this->assertStringContainsString('--user daemon-reload', (string) file_get_contents($invocationLog));
    $this->assertStringContainsString('--user enable --now bigbrothas-recordings-queue.service', (string) file_get_contents($invocationLog));
    }

    public function test_it_installs_numbered_recordings_worker_systemd_units_when_multiple_workers_are_configured(): void
    {
      $binaryDirectory = storage_path('app/private/test-binaries');
      $systemdDirectory = $binaryDirectory.'/systemd-user-multi';
      File::ensureDirectoryExists($binaryDirectory);

      $invocationLog = $binaryDirectory.'/recording-worker-install-multi.log';
      @unlink($invocationLog);

      $systemctlBinary = $binaryDirectory.'/recording-worker-install-multi-systemctl.sh';
      File::put($systemctlBinary, <<<BASH
  #!/usr/bin/env bash
  echo "$*" >> {$invocationLog}
  exit 0
  BASH);
      chmod($systemctlBinary, 0755);

      config()->set('recording.worker.processes', 2);
      config()->set('recording.worker.systemctl_binary', $systemctlBinary);
      config()->set('recording.worker.systemd_user_dir', $systemdDirectory);
      config()->set('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');
      config()->set('recording.worker.php_binary', '/usr/bin/php');
      config()->set('recording.worker.queue', 'recordings,default');
      config()->set('recording.worker.max_jobs', 50);
      config()->set('recording.worker.max_time', 3600);
      config()->set('recording.worker.memory', 256);

      $exitCode = Artisan::call('camera-recordings:install-worker-service');
      $output = Artisan::output();

      $this->assertSame(0, $exitCode);
      $this->assertStringContainsString('Installed and started 2 recordings worker systemd units.', $output);
      $this->assertFileExists($systemdDirectory.'/bigbrothas-recordings-queue-1.service');
      $this->assertFileExists($systemdDirectory.'/bigbrothas-recordings-queue-2.service');
      $this->assertStringContainsString('ExecStart=/usr/bin/php artisan queue:work --queue=recordings,default --max-jobs=50 --max-time=3600 --memory=256', (string) file_get_contents($systemdDirectory.'/bigbrothas-recordings-queue-1.service'));
      $this->assertFileExists($invocationLog);
      $log = (string) file_get_contents($invocationLog);
      $this->assertStringContainsString('--user enable --now bigbrothas-recordings-queue-1.service bigbrothas-recordings-queue-2.service', $log);
      $this->assertStringContainsString('--user disable --now bigbrothas-recordings-queue.service', $log);
    }

    public function test_it_starts_the_recordings_worker_via_systemd_when_enabled(): void
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $invocationLog = $binaryDirectory.'/recording-worker-systemd.log';
        @unlink($invocationLog);

        $psBinary = $binaryDirectory.'/recording-worker-ps.sh';
        File::put($psBinary, <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);
        chmod($psBinary, 0755);

        $systemctlBinary = $binaryDirectory.'/recording-worker-systemctl.sh';
        File::put($systemctlBinary, <<<BASH
#!/usr/bin/env bash
echo "$*" >> {$invocationLog}
if [[ "$*" == *" is-active "* ]]; then
  exit 3
fi
if [[ "$*" == *" start "* ]]; then
  exit 0
fi
exit 1
BASH);
        chmod($systemctlBinary, 0755);

        config()->set('recording.worker.ensure_running', true);
        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.ps_binary', $psBinary);
        config()->set('recording.worker.systemctl_binary', $systemctlBinary);
        config()->set('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');
        config()->set('recording.worker.fallback_start', false);

        $exitCode = Artisan::call('camera-recordings:ensure-worker');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Started the recordings queue worker via the configured systemd unit.', $output);
        $this->assertFileExists($invocationLog);
        $this->assertStringContainsString('--user is-active --quiet bigbrothas-recordings-queue.service', (string) file_get_contents($invocationLog));
        $this->assertStringContainsString('--user start bigbrothas-recordings-queue.service', (string) file_get_contents($invocationLog));
    }

    public function test_it_starts_missing_recordings_workers_via_systemd_when_multiple_workers_are_configured(): void
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $invocationLog = $binaryDirectory.'/recording-worker-systemd-multi.log';
        @unlink($invocationLog);

        $psBinary = $binaryDirectory.'/recording-worker-ps-multi.sh';
        File::put($psBinary, <<<'BASH'
#!/usr/bin/env bash
cat <<'OUT'
 3100 /usr/bin/php artisan queue:work --queue=recordings,default --max-jobs=50 --max-time=3600 --memory=256
 3101 /usr/bin/php artisan queue:work --queue=recordings,default --max-jobs=50 --max-time=3600 --memory=256
OUT
exit 0
BASH);
        chmod($psBinary, 0755);

        $systemctlBinary = $binaryDirectory.'/recording-worker-systemctl-multi.sh';
        File::put($systemctlBinary, <<<BASH
#!/usr/bin/env bash
echo "$*" >> {$invocationLog}
if [[ "$*" == *" is-active "*"bigbrothas-recordings-queue-1.service"* ]]; then
  exit 0
fi
if [[ "$*" == *" is-active "*"bigbrothas-recordings-queue-2.service"* ]]; then
  exit 3
fi
if [[ "$*" == *" start "*"bigbrothas-recordings-queue-2.service"* ]]; then
  exit 0
fi
exit 1
BASH);
        chmod($systemctlBinary, 0755);

        config()->set('recording.worker.ensure_running', true);
        config()->set('recording.worker.processes', 2);
        config()->set('recording.worker.ps_binary', $psBinary);
        config()->set('recording.worker.systemctl_binary', $systemctlBinary);
        config()->set('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');
        config()->set('recording.worker.fallback_start', false);

        $exitCode = Artisan::call('camera-recordings:ensure-worker');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Started 1 recordings queue worker via the configured systemd units.', $output);
        $this->assertFileExists($invocationLog);
        $log = (string) file_get_contents($invocationLog);
        $this->assertStringContainsString('--user is-active --quiet bigbrothas-recordings-queue-1.service', $log);
        $this->assertStringContainsString('--user is-active --quiet bigbrothas-recordings-queue-2.service', $log);
        $this->assertStringContainsString('--user start bigbrothas-recordings-queue-2.service', $log);
    }

      public function test_it_scales_installed_worker_units_from_recording_camera_demand(): void
      {
        $binaryDirectory = storage_path('app/private/test-binaries');
        $systemdDirectory = $binaryDirectory.'/systemd-user-dynamic';
        File::ensureDirectoryExists($binaryDirectory);

        $invocationLog = $binaryDirectory.'/recording-worker-install-dynamic.log';
        @unlink($invocationLog);

        $systemctlBinary = $binaryDirectory.'/recording-worker-install-dynamic-systemctl.sh';
        File::put($systemctlBinary, <<<BASH
    #!/usr/bin/env bash
    echo "$*" >> {$invocationLog}
    exit 0
    BASH);
        chmod($systemctlBinary, 0755);

        for ($index = 1; $index <= 9; $index++) {
          Camera::query()->create([
            'name' => 'Dynamic Cam '.$index,
            'local_ip' => '192.168.1.'.(100 + $index),
            'rtsp_port' => 554,
            'rtsp_path' => '/stream'.$index,
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
          ]);
        }

        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.dynamic_enabled', true);
        config()->set('recording.worker.max_processes', 6);
        config()->set('recording.worker.cameras_per_process', 4);
        config()->set('recording.worker.jobs_per_process', 200);
        config()->set('recording.worker.systemctl_binary', $systemctlBinary);
        config()->set('recording.worker.systemd_user_dir', $systemdDirectory);
        config()->set('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');
        config()->set('recording.worker.php_binary', '/usr/bin/php');
        config()->set('recording.worker.queue', 'recordings,default');
        config()->set('recording.worker.max_jobs', 50);
        config()->set('recording.worker.max_time', 3600);
        config()->set('recording.worker.memory', 256);

        $exitCode = Artisan::call('camera-recordings:install-worker-service');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Installed and started 3 recordings worker systemd units.', $output);
        $this->assertFileExists($systemdDirectory.'/bigbrothas-recordings-queue-1.service');
        $this->assertFileExists($systemdDirectory.'/bigbrothas-recordings-queue-2.service');
        $this->assertFileExists($systemdDirectory.'/bigbrothas-recordings-queue-3.service');
      }

      public function test_it_reconciles_missing_worker_units_when_dynamic_capacity_grows(): void
      {
        $binaryDirectory = storage_path('app/private/test-binaries');
        $systemdDirectory = $binaryDirectory.'/systemd-user-dynamic-ensure';
        File::ensureDirectoryExists($binaryDirectory);

        $invocationLog = $binaryDirectory.'/recording-worker-systemd-dynamic.log';
        @unlink($invocationLog);

        $psBinary = $binaryDirectory.'/recording-worker-ps-dynamic.sh';
        File::put($psBinary, <<<'BASH'
    #!/usr/bin/env bash
    exit 0
    BASH);
        chmod($psBinary, 0755);

        $systemctlBinary = $binaryDirectory.'/recording-worker-systemctl-dynamic.sh';
        File::put($systemctlBinary, <<<BASH
    #!/usr/bin/env bash
    echo "$*" >> {$invocationLog}
    if [[ "$*" == *" daemon-reload"* ]]; then
      exit 0
    fi
    if [[ "$*" == *" enable "* ]]; then
      exit 0
    fi
    if [[ "$*" == *" disable "* ]]; then
      exit 0
    fi
    if [[ "$*" == *" is-active "* ]]; then
      exit 3
    fi
    if [[ "$*" == *" start "* ]]; then
      exit 0
    fi
    exit 1
    BASH);
        chmod($systemctlBinary, 0755);

        for ($index = 1; $index <= 9; $index++) {
          Camera::query()->create([
            'name' => 'Growing Cam '.$index,
            'local_ip' => '192.168.1.'.(120 + $index),
            'rtsp_port' => 554,
            'rtsp_path' => '/stream'.$index,
            'supports_onvif' => false,
            'supports_rtsp' => true,
            'is_enabled' => true,
            'recording_mode' => Camera::RECORDING_MODE_CONTINUOUS,
            'recording_retention_days' => 1,
          ]);
        }

        config()->set('recording.worker.ensure_running', true);
        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.dynamic_enabled', true);
        config()->set('recording.worker.max_processes', 6);
        config()->set('recording.worker.cameras_per_process', 4);
        config()->set('recording.worker.jobs_per_process', 200);
        config()->set('recording.worker.ps_binary', $psBinary);
        config()->set('recording.worker.systemctl_binary', $systemctlBinary);
        config()->set('recording.worker.systemd_user_dir', $systemdDirectory);
        config()->set('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');
        config()->set('recording.worker.php_binary', '/usr/bin/php');
        config()->set('recording.worker.queue', 'recordings,default');
        config()->set('recording.worker.max_jobs', 50);
        config()->set('recording.worker.max_time', 3600);
        config()->set('recording.worker.memory', 256);
        config()->set('recording.worker.fallback_start', false);

        $exitCode = Artisan::call('camera-recordings:ensure-worker');
        $output = Artisan::output();
        $log = (string) file_get_contents($invocationLog);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Started 3 recordings queue workers via the configured systemd units.', $output);
        $this->assertFileExists($systemdDirectory.'/bigbrothas-recordings-queue-1.service');
        $this->assertFileExists($systemdDirectory.'/bigbrothas-recordings-queue-2.service');
        $this->assertFileExists($systemdDirectory.'/bigbrothas-recordings-queue-3.service');
        $this->assertStringContainsString('--user daemon-reload', $log);
        $this->assertStringContainsString('--user enable bigbrothas-recordings-queue-1.service bigbrothas-recordings-queue-2.service bigbrothas-recordings-queue-3.service', $log);
        $this->assertStringContainsString('--user start bigbrothas-recordings-queue-1.service', $log);
        $this->assertStringContainsString('--user start bigbrothas-recordings-queue-2.service', $log);
        $this->assertStringContainsString('--user start bigbrothas-recordings-queue-3.service', $log);
      }

      public function test_it_scales_worker_units_from_queue_backlog_within_the_configured_cap(): void
      {
        $binaryDirectory = storage_path('app/private/test-binaries');
        $systemdDirectory = $binaryDirectory.'/systemd-user-backlog';
        File::ensureDirectoryExists($binaryDirectory);

        $invocationLog = $binaryDirectory.'/recording-worker-install-backlog.log';
        @unlink($invocationLog);

        $systemctlBinary = $binaryDirectory.'/recording-worker-install-backlog-systemctl.sh';
        File::put($systemctlBinary, <<<BASH
    #!/usr/bin/env bash
    echo "$*" >> {$invocationLog}
    exit 0
    BASH);
        chmod($systemctlBinary, 0755);

        foreach (range(1, 450) as $index) {
          DB::table('jobs')->insert([
            'queue' => 'recordings',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\GenerateRecordingReviewAssetsJob']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
          ]);
        }

        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.dynamic_enabled', true);
        config()->set('recording.worker.max_processes', 3);
        config()->set('recording.worker.cameras_per_process', 10);
        config()->set('recording.worker.jobs_per_process', 150);
        config()->set('recording.worker.systemctl_binary', $systemctlBinary);
        config()->set('recording.worker.systemd_user_dir', $systemdDirectory);
        config()->set('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');
        config()->set('recording.worker.php_binary', '/usr/bin/php');
        config()->set('recording.worker.queue', 'recordings,default');
        config()->set('recording.worker.max_jobs', 50);
        config()->set('recording.worker.max_time', 3600);
        config()->set('recording.worker.memory', 256);

        $exitCode = Artisan::call('camera-recordings:install-worker-service');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Installed and started 3 recordings worker systemd units.', $output);
      }

    public function test_it_noops_when_worker_supervision_is_disabled(): void
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        File::ensureDirectoryExists($binaryDirectory);

        $psBinary = $binaryDirectory.'/recording-worker-ps-empty.sh';
        File::put($psBinary, <<<'BASH'
#!/usr/bin/env bash
exit 0
BASH);
        chmod($psBinary, 0755);

        config()->set('recording.worker.ensure_running', false);
        config()->set('recording.worker.ps_binary', $psBinary);

        $exitCode = Artisan::call('camera-recordings:ensure-worker');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Recordings worker supervision is disabled.', $output);
    }

    public function test_it_can_fail_gracefully_when_worker_service_install_is_unavailable(): void
    {
        $binaryDirectory = storage_path('app/private/test-binaries');
        $systemdDirectory = $binaryDirectory.'/systemd-user-graceful';
        File::ensureDirectoryExists($binaryDirectory);

        config()->set('recording.worker.processes', 1);
        config()->set('recording.worker.systemctl_binary', $binaryDirectory.'/missing-systemctl');
        config()->set('recording.worker.systemd_user_dir', $systemdDirectory);
        config()->set('recording.worker.systemd_service', 'bigbrothas-recordings-queue.service');

        $exitCode = Artisan::call('camera-recordings:install-worker-service', [
          '--graceful' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('systemd daemon-reload failed', $output);
    }
}