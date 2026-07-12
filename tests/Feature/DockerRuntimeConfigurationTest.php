<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class DockerRuntimeConfigurationTest extends TestCase
{
    public function test_compose_runtime_has_exactly_four_services(): void
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        $this->assertIsString($compose);
        $servicesBlock = explode("\nvolumes:\n", explode("services:\n", $compose, 2)[1], 2)[0];
        preg_match_all('/^  ([a-z][a-z0-9_-]*):$/m', $servicesBlock, $matches);
        $services = $matches[1];
        sort($services);

        $this->assertSame(['app', 'background', 'database', 'relay'], $services);
        $this->assertStringContainsString('MEDIAMTX_AUTH_CALLBACK_URL: http://app:8080/relay/auth/mediamtx', $compose);
        $this->assertStringContainsString('command: ["run-background"]', $compose);
        $this->assertStringContainsString('${WEB_PORT:-8082}:8080', $compose);
        $this->assertStringNotContainsString('BIGBROTHA_WEB_IMAGE', $compose);
    }

    public function test_unified_image_contains_the_required_process_runtime(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $buildOverride = file_get_contents(base_path('docker-compose.build.yml'));

        $this->assertIsString($dockerfile);
        $this->assertIsString($buildOverride);
        $this->assertStringContainsString('nginx-light', $dockerfile);
        $this->assertStringContainsString('supervisor', $dockerfile);
        $this->assertStringContainsString('COPY docker/supervisor/app.conf', $dockerfile);
        $this->assertStringContainsString('COPY docker/supervisor/background.conf', $dockerfile);
        $this->assertStringContainsString('COPY docker/php-fpm-production.conf', $dockerfile);
        $this->assertStringContainsString('CMD ["run-app"]', $dockerfile);
        $this->assertStringNotContainsString("\n  web:\n", $buildOverride);
    }

    public function test_supervisor_groups_preserve_process_count_and_shutdown_semantics(): void
    {
        $appSupervisor = file_get_contents(base_path('docker/supervisor/app.conf'));
        $backgroundSupervisor = file_get_contents(base_path('docker/supervisor/background.conf'));

        $this->assertIsString($appSupervisor);
        $this->assertIsString($backgroundSupervisor);
        $this->assertStringContainsString('[program:php-fpm]', $appSupervisor);
        $this->assertStringContainsString('[program:nginx]', $appSupervisor);
        $this->assertStringContainsString('[program:service-logs]', $appSupervisor);
        $this->assertSame(3, substr_count($appSupervisor, 'user=www-data'));
        $this->assertStringContainsString('[program:scheduler]', $backgroundSupervisor);
        $this->assertStringContainsString('[program:worker]', $backgroundSupervisor);
        $this->assertStringContainsString('numprocs=%(ENV_CAMERA_RECORDING_WORKER_PROCESSES)s', $backgroundSupervisor);
        $this->assertStringContainsString('command=/usr/local/bin/run-worker worker_%(process_num)02d', $backgroundSupervisor);
        $this->assertSame(2, substr_count($backgroundSupervisor, 'stopasgroup=true'));
        $this->assertSame(2, substr_count($backgroundSupervisor, 'killasgroup=true'));
    }

    public function test_docker_shell_entrypoints_are_syntactically_valid(): void
    {
        $scripts = [
            'docker/entrypoint.sh',
            'docker/healthcheck-app.sh',
            'docker/healthcheck-background.sh',
            'docker/run-app.sh',
            'docker/run-background.sh',
            'docker/run-worker.sh',
            'docker/run-scheduler.sh',
            'publish.sh',
        ];

        foreach ($scripts as $script) {
            $process = new Process(['sh', '-n', base_path($script)]);
            $process->run();

            $this->assertTrue($process->isSuccessful(), $script.': '.$process->getErrorOutput());
        }
    }
}
