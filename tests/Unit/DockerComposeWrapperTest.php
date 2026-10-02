<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DockerComposeWrapperTest extends TestCase
{
    private string $installation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->installation = sys_get_temp_dir().'/bigbrotha-wrapper-'.bin2hex(random_bytes(8));
        mkdir($this->installation.'/docker', 0700, true);
        mkdir($this->installation.'/bin', 0700);
        $root = dirname(__DIR__, 2);

        foreach (['docker/compose.sh', 'docker/compose-up.sh', 'docker/compose-dev.sh', '.env.docker.example'] as $file) {
            copy($root.'/'.$file, $this->installation.'/'.$file);
            chmod($this->installation.'/'.$file, fileperms($root.'/'.$file) & 0777);
        }

        file_put_contents($this->installation.'/bin/docker', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$DOCKER_CALL_LOG"
if [ "$1" = info ]; then exit 0; fi
shift
printf '%s\n' "$@"
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
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->installation);
        parent::tearDown();
    }

    public function test_init_prepares_private_configuration_without_docker_and_preserves_the_password(): void
    {
        $this->runWrapper('compose.sh', ['init']);
        $envFile = $this->installation.'/.env.docker';
        $contents = file_get_contents($envFile);
        preg_match('/^DB_PASSWORD=(.+)$/m', $contents, $password);

        $this->assertGreaterThanOrEqual(40, strlen($password[1]));
        $this->assertSame(0600, fileperms($envFile) & 0777);
        $this->assertSame(0700, fileperms($this->installation.'/.docker-state') & 0777);
        $this->assertFileDoesNotExist($this->installation.'/docker-calls');

        $this->runWrapper('compose.sh', ['init']);
        $this->assertSame($contents, file_get_contents($envFile));
    }

    #[DataProvider('startCommands')]
    public function test_start_selects_the_matching_image_mode_and_waits_for_health(
        string $script, array $arguments, ?string $overlay, string $action,
    ): void {
        $command = $this->runWrapper($script, [...$arguments, '--wait-timeout', '120']);

        $this->assertContains($this->installation.'/docker-compose.yml', $command);
        $this->assertContains($this->installation.'/.env.docker', $command);
        $this->assertContains('up', $command);
        $this->assertContains('-d', $command);
        $this->assertContains('--wait', $command);
        $this->assertContains('--remove-orphans', $command);
        $this->assertContains('--wait-timeout', $command);
        $this->assertContains('120', $command);
        $this->assertContains($action, $command);

        if ($overlay !== null) {
            $this->assertContains($this->installation.'/'.$overlay, $command);
            $this->assertNotContains('--pull', $command);
        } else {
            $this->assertContains('always', $command);
            $this->assertNotContains('--build', $command);
            $this->assertSame(1, count(array_filter($command, fn ($argument) => $argument === '-f')));
        }
    }

    public static function startCommands(): array
    {
        return [
            'published' => ['compose.sh', ['start'], null, '--pull'],
            'local' => ['compose.sh', ['--local', 'start'], 'docker-compose.build.yml', '--build'],
            'development' => ['compose.sh', ['--dev', 'start'], 'docker-compose.dev.yml', '--build'],
            'source shortcut' => ['compose-up.sh', [], 'docker-compose.build.yml', '--build'],
            'development shortcut' => ['compose-dev.sh', ['start'], 'docker-compose.dev.yml', '--build'],
        ];
    }

    public function test_normal_compose_commands_pass_through_in_local_mode(): void
    {
        $command = $this->runWrapper('compose.sh', ['--local', 'logs', '--tail=20', 'app']);

        $this->assertSame(['logs', '--tail=20', 'app'], array_slice($command, -3));
        $this->assertContains($this->installation.'/docker-compose.build.yml', $command);
        $this->assertNotContains('--build', $command);
        $this->assertNotContains('--pull', $command);
    }

    private function runWrapper(string $script, array $arguments): array
    {
        $process = new Process(['sh', $this->installation.'/docker/'.$script, ...$arguments], '/', [
            'PATH' => $this->installation.'/bin:'.getenv('PATH'),
            'BIGBROTHA_DOCKER_ENV_FILE' => false,
            'DOCKER_CALL_LOG' => $this->installation.'/docker-calls',
        ]);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return explode("\n", trim($process->getOutput()));
    }
}
