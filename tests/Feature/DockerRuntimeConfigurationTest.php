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
        $this->assertStringContainsString('command: ["run-background"]', $compose);
        $this->assertStringContainsString('command: ["run-relay"]', $compose);
        $this->assertStringContainsString('${WEB_PORT:-8082}:8080', $compose);
        $this->assertStringNotContainsString('GOOGLE_CLIENT_ID', $compose);
        $this->assertStringNotContainsString('BIGBROTHA_WEB_IMAGE', $compose);
    }

    public function test_unified_image_contains_the_required_process_runtime(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));
        $buildOverride = file_get_contents(base_path('docker-compose.build.yml'));
        $services = file_get_contents(base_path('config/services.php'));

        $this->assertIsString($dockerfile);
        $this->assertIsString($buildOverride);
        $this->assertIsString($services);
        $this->assertStringContainsString('nginx-light', $dockerfile);
        $this->assertStringContainsString('supervisor', $dockerfile);
        $this->assertStringContainsString('COPY docker/supervisor/app.conf', $dockerfile);
        $this->assertStringContainsString('COPY docker/supervisor/background.conf', $dockerfile);
        $this->assertStringContainsString('COPY docker/php-fpm-production.conf', $dockerfile);
        $this->assertStringContainsString('APP_KEY_FILE=/app/bootstrap-persist/app.key', $dockerfile);
        $this->assertStringContainsString('MEDIAMTX_AUTH_CALLBACK_URL=http://app:8080/relay/auth/mediamtx', $dockerfile);
        $this->assertStringContainsString('COPY docker/healthcheck.sh /usr/local/bin/healthcheck', $dockerfile);
        $this->assertStringContainsString('COPY docker/run-relay.sh /usr/local/bin/run-relay', $dockerfile);
        $this->assertStringContainsString('CMD ["/usr/local/bin/healthcheck"]', $dockerfile);
        $this->assertStringContainsString('CMD ["run-app"]', $dockerfile);
        $this->assertStringNotContainsString("env('GOOGLE_", $services);
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
            'docker/compose.sh',
            'docker/compose-dev.sh',
            'docker/compose-up.sh',
            'docker/entrypoint.sh',
            'docker/healthcheck.sh',
            'docker/healthcheck-app.sh',
            'docker/healthcheck-background.sh',
            'docker/healthcheck-relay.sh',
            'docker/run-app.sh',
            'docker/run-background.sh',
            'docker/run-relay.sh',
            'docker/run-worker.sh',
            'docker/run-scheduler.sh',
            'docker/verify-image.sh',
            'publish.sh',
        ];

        foreach ($scripts as $script) {
            $process = new Process(['sh', '-n', base_path($script)]);
            $process->run();

            $this->assertTrue($process->isSuccessful(), $script.': '.$process->getErrorOutput());
        }
    }

    public function test_shutdown_budgets_allow_jobs_to_finish_before_the_container_is_killed(): void
    {
        $supervisor = file_get_contents(base_path('docker/supervisor/background.conf'));
        $compose = file_get_contents(base_path('docker-compose.yml'));
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        preg_match('/\[program:worker\][\s\S]*?^stopwaitsecs=(\d+)$/m', $supervisor, $worker);
        preg_match('/\[program:scheduler\][\s\S]*?^stopwaitsecs=(\d+)$/m', $supervisor, $scheduler);
        $background = explode("  relay:\n", explode("  background:\n", $compose, 2)[1], 2)[0];
        preg_match('/stop_grace_period: (\d+)m/', $background, $grace);

        $this->assertCount(2, $worker);
        $this->assertCount(2, $scheduler);
        $this->assertCount(2, $grace);
        $this->assertGreaterThan(max(
            config('recording.job_timeout_seconds'),
            config('recording.review_assets.job_timeout_seconds'),
        ), (int) $worker[1]);
        $this->assertGreaterThan((int) $worker[1] + (int) $scheduler[1], (int) $grace[1] * 60);
        $this->assertStringContainsString("\nSTOPSIGNAL SIGTERM\n", $dockerfile);
    }

    public function test_relay_uses_a_writable_working_directory_for_generated_tls_files(): void
    {
        $relay = file_get_contents(base_path('docker/run-relay.sh'));

        $this->assertIsString($relay);
        $this->assertStringContainsString("cd /tmp\n", $relay);
    }

    public function test_scheduler_clears_interrupted_overlap_locks_before_the_bootstrap_tick(): void
    {
        $scheduler = file_get_contents(base_path('docker/run-scheduler.sh'));

        $this->assertIsString($scheduler);
        $clearPosition = strpos($scheduler, 'php artisan schedule:clear-cache --no-interaction');
        $tickPosition = strpos($scheduler, 'php artisan camera-recordings:tick --no-interaction');

        $this->assertIsInt($clearPosition);
        $this->assertIsInt($tickPosition);
        $this->assertLessThan($tickPosition, $clearPosition);
    }

    public function test_container_startup_repairs_persistent_recorder_runtime_permissions(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('normalize_recording_runtime_permissions', $entrypoint);
        $this->assertStringContainsString('storage/app/private/continuous-recorders', $entrypoint);
        $this->assertStringContainsString('storage/app/private/motion-recorders', $entrypoint);
        $this->assertStringContainsString('chown -R www-data:www-data "$runtime_path"', $entrypoint);
        $this->assertStringContainsString('find "$runtime_path" -type d -exec chmod 2775 {} +', $entrypoint);
    }

    public function test_production_runtime_warms_safe_caches_without_persisting_database_backed_secrets(): void
    {
        $entrypoint = file_get_contents(base_path('docker/entrypoint.sh'));

        $this->assertIsString($entrypoint);
        $this->assertStringContainsString('warm_runtime_caches', $entrypoint);
        $this->assertStringContainsString('php artisan config:clear', $entrypoint);
        $this->assertStringContainsString('php artisan event:cache', $entrypoint);
        $this->assertStringContainsString('php artisan route:cache', $entrypoint);
        $this->assertStringContainsString('php artisan view:cache', $entrypoint);
        $this->assertStringNotContainsString('php artisan optimize', $entrypoint);
    }

    public function test_production_http_and_php_runtime_enable_compression_and_bounded_caches(): void
    {
        $nginx = file_get_contents(base_path('docker/nginx/nginx.conf'));
        $site = file_get_contents(base_path('docker/nginx/default.conf'));
        $php = file_get_contents(base_path('docker/php-production.ini'));
        $fpm = file_get_contents(base_path('docker/php-fpm-production.conf'));

        $this->assertIsString($nginx);
        $this->assertIsString($site);
        $this->assertIsString($php);
        $this->assertIsString($fpm);
        $this->assertStringContainsString('gzip on;', $nginx);
        $this->assertStringContainsString('gzip_vary on;', $nginx);
        $this->assertStringContainsString('open_file_cache max=1000', $nginx);
        $this->assertStringContainsString('^/css/.*\.css$', $site);
        $this->assertStringContainsString('expires -1;', $site);
        $this->assertStringContainsString('^/(?:img|js)/', $site);
        $this->assertStringContainsString('expires 1h;', $site);
        $this->assertStringContainsString('opcache.validate_timestamps = Off', $php);
        $this->assertStringContainsString('realpath_cache_size = 4096K', $php);
        $this->assertStringContainsString('pm.max_children = 8', $fpm);
        $this->assertStringContainsString('pm.max_requests = 500', $fpm);
    }
}
