<?php

namespace Tests\Feature\Relay;

use App\Services\Relay\MediaMtxPathStatusService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaMtxPathStatusServiceTest extends TestCase
{
    public function test_active_paths_fall_back_to_the_configured_api_address_when_the_base_url_port_is_stale(): void
    {
        Http::fake([
            'http://relay:9998/*' => Http::failedConnection(),
            'http://relay:9997/v3/paths/list' => Http::response([
                'items' => [
                    [
                        'name' => 'camera-12-live',
                        'ready' => true,
                        'online' => true,
                    ],
                    [
                        'name' => 'camera-12-source-profile-0',
                        'ready' => false,
                        'online' => true,
                    ],
                ],
            ], 200),
        ]);

        config()->set('mediamtx.api.base_url', 'http://relay:9998');
        config()->set('mediamtx.api.address', ':9997');

        $service = new MediaMtxPathStatusService();

        $this->assertSame([
            'camera-12-live' => true,
        ], $service->activePaths());
    }
}