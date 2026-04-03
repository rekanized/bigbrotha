<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RecordingWorkerCommandTest extends TestCase
{
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