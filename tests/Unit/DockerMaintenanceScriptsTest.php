<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DockerMaintenanceScriptsTest extends TestCase
{
    private string $installation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installation = sys_get_temp_dir().'/bigbrotha-maintenance-'.bin2hex(random_bytes(8));
        mkdir($this->installation.'/docker', 0700, true);
        mkdir($this->installation.'/bin', 0700);
        $root = dirname(__DIR__, 2);

        foreach (['docker/rotate-db-password.sh', 'docker/migrate-postgres-18-volume.sh', 'docker-compose.yml'] as $file) {
            copy($root.'/'.$file, $this->installation.'/'.$file);
        }

        file_put_contents($this->installation.'/bin/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$DOCKER_CALL_LOG"
case "$1" in
    info) exit 0 ;;
    compose)
        shift
        while [ "$#" -gt 0 ]; do
            case "$1" in
                --project-directory|--env-file|-f) shift 2 ;;
                *) break ;;
            esac
        done
        case "$1" in
            ps) echo fixture-database ;;
            up) exit "${FAKE_UP_EXIT_CODE:-0}" ;;
        esac
        ;;
    exec)
        if [ "$2" = -i ]; then
            cat > "$SQL_CALL_LOG"
        else
            printf '%s' fixture_user
        fi
        ;;
    inspect)
        case "$*" in
            */var/lib/postgresql/data*) ;;
            *) echo fixture-db-data ;;
        esac
        ;;
esac
SH);
        chmod($this->installation.'/bin/docker', 0700);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->installation, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->installation);
        parent::tearDown();
    }

    public function test_rotation_preserves_the_env_link_and_existing_setup_values(): void
    {
        $original = "APP_URL=https://fixture.example.invalid\nDB_PASSWORD=fixture-old-password\nCOMPOSE_PROJECT_NAME=fixture-project\n";
        file_put_contents($this->installation.'/.env.docker', $original);
        symlink('.env.docker', $this->installation.'/.env');

        $process = $this->runScript('rotate-db-password.sh');
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertTrue(is_link($this->installation.'/.env'));
        $updated = file_get_contents($this->installation.'/.env.docker');
        $this->assertSame($updated, file_get_contents($this->installation.'/.env'));
        $this->assertStringContainsString('APP_URL=https://fixture.example.invalid', $updated);
        $this->assertStringContainsString('COMPOSE_PROJECT_NAME=fixture-project', $updated);
        $this->assertStringNotContainsString('DB_PASSWORD=fixture-old-password', $updated);
        $this->assertSame(0600, fileperms($this->installation.'/.env.docker') & 0777);
        $backups = glob($this->installation.'/.docker-state/env-before-db-password-rotation-*');
        $this->assertCount(1, $backups);
        $this->assertSame($original, file_get_contents($backups[0]));
        $this->assertStringContainsString('--wait --wait-timeout 180', file_get_contents($this->installation.'/docker-calls'));
        $this->assertStringContainsString('ALTER ROLE "fixture_user" PASSWORD', file_get_contents($this->installation.'/sql-calls'));
        $this->assertSame([], glob($this->installation.'/.env.docker.tmp.*'));
    }

    public function test_rotation_uses_the_same_env_as_plain_compose_when_both_files_exist(): void
    {
        file_put_contents($this->installation.'/.env', "APP_URL=https://current.example.invalid\nDB_PASSWORD=fixture-current-password\n");
        $legacy = "APP_URL=https://legacy.example.invalid\nDB_PASSWORD=fixture-legacy-password\n";
        file_put_contents($this->installation.'/.env.docker', $legacy);

        $process = $this->runScript('rotate-db-password.sh');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame($legacy, file_get_contents($this->installation.'/.env.docker'));
        $this->assertStringNotContainsString('fixture-current-password', file_get_contents($this->installation.'/.env'));
    }

    public function test_rotation_does_not_report_success_when_services_fail_to_become_healthy(): void
    {
        file_put_contents($this->installation.'/.env', "APP_URL=https://fixture.example.invalid\nDB_PASSWORD=fixture-old-password\n");

        $process = $this->runScript('rotate-db-password.sh', ['FAKE_UP_EXIT_CODE' => '1']);

        $this->assertFalse($process->isSuccessful());
        $this->assertStringContainsString('Services did not become healthy', $process->getErrorOutput());
        $this->assertStringNotContainsString('rotation completed successfully', $process->getOutput());
        $this->assertCount(1, glob($this->installation.'/.docker-state/env-before-db-password-rotation-*'));
    }

    public function test_postgres_helper_leaves_an_already_correct_volume_layout_untouched(): void
    {
        file_put_contents($this->installation.'/.env', "APP_URL=https://fixture.example.invalid\nDB_PASSWORD=fixture-password\n");

        $process = $this->runScript('migrate-postgres-18-volume.sh');

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringContainsString('no migration is needed', $process->getOutput());
        $calls = file_get_contents($this->installation.'/docker-calls');
        $this->assertStringNotContainsString('down', $calls);
        $this->assertStringNotContainsString('pg_dumpall', $calls);
        $this->assertDirectoryDoesNotExist($this->installation.'/.docker-state');
    }

    private function runScript(string $script, array $environment = []): Process
    {
        $process = new Process(['sh', $this->installation.'/docker/'.$script], '/', [
            'PATH' => $this->installation.'/bin:'.getenv('PATH'),
            'BIGBROTHA_DEPLOY_DIR' => $this->installation,
            'BIGBROTHA_DOCKER_ENV_FILE' => false,
            'DOCKER_CALL_LOG' => $this->installation.'/docker-calls',
            'SQL_CALL_LOG' => $this->installation.'/sql-calls',
            ...$environment,
        ]);
        $process->run();

        return $process;
    }
}
