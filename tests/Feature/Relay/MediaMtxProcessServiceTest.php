<?php

namespace Tests\Feature\Relay;

use App\Services\Relay\MediaMtxConfigService;
use App\Services\Relay\MediaMtxInstaller;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaMtxProcessServiceTest extends TestCase
{
    public function test_status_cleans_up_a_stale_pid_file_when_no_matching_process_exists(): void
    {
        Http::fake([
            'http://127.0.0.1:9997/*' => Http::response([], 503),
        ]);

        $pidPath = storage_path('framework/testing/mediamtx-stale.pid');
        $binaryPath = storage_path('framework/testing/mediamtx-binary');
        $configPath = storage_path('framework/testing/mediamtx.yml');

        @mkdir(dirname($pidPath), 0777, true);
        file_put_contents($pidPath, '999999');
        file_put_contents($binaryPath, 'binary');
        file_put_contents($configPath, 'config');

        config()->set('mediamtx.pid_path', $pidPath);
        config()->set('mediamtx.binary_path', $binaryPath);
        config()->set('mediamtx.config_path', $configPath);

        $installer = new class($binaryPath) extends MediaMtxInstaller
        {
            public function __construct(private readonly string $binaryPath)
            {
            }

            public function isInstalled(): bool
            {
                return true;
            }

            public function binaryPath(): string
            {
                return $this->binaryPath;
            }
        };

        $configService = $this->createMock(MediaMtxConfigService::class);
        $service = new MediaMtxProcessService($installer, $configService);

        $status = $service->status();

        $this->assertFalse($status['running']);
        $this->assertFalse($status['api_reachable']);
        $this->assertNull($status['pid']);
        $this->assertFileDoesNotExist($pidPath);
    }
}