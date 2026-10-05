<?php

namespace Tests\Feature\Relay;

use App\Services\Relay\MediaMtxConfigService;
use App\Services\Relay\MediaMtxInstaller;
use App\Services\Relay\MediaMtxProcessService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaMtxProcessServiceTest extends TestCase
{
    public function test_sync_publishes_complete_config_snapshots_and_skips_unchanged_content(): void
    {
        $path = storage_path('app/private/mediamtx/mediamtx.yml');
        config()->set('mediamtx.config_path', $path);
        $configService = $this->createMock(MediaMtxConfigService::class);
        $configService->method('buildConfig')->willReturnOnConsecutiveCalls(
            "paths: {}\n", "paths: {}\n", "paths: {camera-1-live: {}}\n",
        );
        $service = new MediaMtxProcessService(new MediaMtxInstaller, $configService);
        $this->assertTrue($service->syncConfig());
        $firstInode = fileinode($path);
        $this->assertFalse($service->syncConfig());
        $this->assertSame($firstInode, fileinode($path));
        $this->assertTrue($service->syncConfig());
        clearstatcache(true, $path);
        $this->assertNotSame($firstInode, fileinode($path));
        $this->assertSame("paths: {camera-1-live: {}}\n", file_get_contents($path));
    }

    public function test_status_uses_the_api_when_mediamtx_is_managed_externally(): void
    {
        Http::fake([
            'http://relay:9997/*' => Http::response(['items' => []], 200),
        ]);

        config()->set('mediamtx.managed_externally', true);
        config()->set('mediamtx.api.base_url', 'http://relay:9997');

        $configService = $this->createMock(MediaMtxConfigService::class);
        $configService->expects($this->never())->method($this->anything());

        $service = new MediaMtxProcessService(new MediaMtxInstaller, $configService);

        $status = $service->status();

        $this->assertTrue($status['installed']);
        $this->assertTrue($status['running']);
        $this->assertTrue($status['api_reachable']);
        $this->assertNull($status['pid']);
    }

    public function test_status_falls_back_to_the_configured_api_address_when_the_base_url_port_is_stale(): void
    {
        Http::fake([
            'http://relay:9998/*' => Http::failedConnection(),
            'http://relay:9997/*' => Http::response(['version' => '1.17.1'], 200),
        ]);

        config()->set('mediamtx.managed_externally', true);
        config()->set('mediamtx.api.base_url', 'http://relay:9998');
        config()->set('mediamtx.api.address', ':9997');

        $configService = $this->createMock(MediaMtxConfigService::class);
        $configService->expects($this->never())->method($this->anything());

        $service = new MediaMtxProcessService(new MediaMtxInstaller, $configService);

        $status = $service->status();

        $this->assertTrue($status['running']);
        $this->assertTrue($status['api_reachable']);
    }

    public function test_status_cleans_up_a_stale_pid_file_when_no_matching_process_exists(): void
    {
        Http::fake([
            'http://127.0.0.1:19997/*' => Http::response([], 503),
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
        config()->set('mediamtx.api.base_url', 'http://127.0.0.1:19997');
        config()->set('mediamtx.api.address', ':19997');
        config()->set('mediamtx.managed_externally', false);

        $installer = new class($binaryPath) extends MediaMtxInstaller
        {
            public function __construct(private readonly string $binaryPath) {}

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
