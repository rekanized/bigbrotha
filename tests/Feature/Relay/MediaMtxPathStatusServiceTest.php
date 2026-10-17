<?php

namespace Tests\Feature\Relay;

use App\Services\Relay\MediaMtxPathStatusService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MediaMtxPathStatusServiceTest extends TestCase
{
    public function test_active_paths_follow_pagination_and_reuse_the_result_within_the_request(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://relay:9997/v3/paths/list' => Http::response([
                'pageCount' => 2,
                'items' => [
                    ['name' => 'camera-1-live', 'available' => true, 'online' => true],
                    ['name' => 'camera-2-live', 'available' => false, 'ready' => true, 'online' => true],
                    ['name' => 'camera-3-live', 'available' => true, 'online' => false],
                    ['name' => '', 'available' => true, 'online' => true],
                    null,
                ],
            ]),
            'http://relay:9997/v3/paths/list?page=1' => Http::response([
                'pageCount' => 2,
                'items' => [
                    ['name' => 'camera-101-live', 'available' => true, 'online' => true],
                    ['name' => 'camera-102-live', 'ready' => true, 'online' => true],
                ],
            ]),
        ]);
        config()->set('mediamtx.api.base_url', 'http://relay:9997');
        config()->set('mediamtx.api.address', ':9997');

        $service = new MediaMtxPathStatusService;
        $expected = ['camera-1-live' => true, 'camera-101-live' => true, 'camera-102-live' => true];

        $this->assertSame($expected, $service->activePaths());
        $this->assertSame($expected, $service->activePaths());
        Http::assertSentCount(2);
    }

    public function test_a_failed_later_page_retains_known_active_paths_without_repeated_requests(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://relay:9997/v3/paths/list' => Http::response([
                'pageCount' => 3,
                'items' => [['name' => 'camera-1-live', 'available' => true, 'online' => true]],
            ]),
            'http://relay:9997/v3/paths/list?page=1' => Http::response([], 503),
        ]);
        config()->set('mediamtx.api.base_url', 'http://relay:9997');
        config()->set('mediamtx.api.address', ':9997');

        $service = new MediaMtxPathStatusService;

        $this->assertSame(['camera-1-live' => true], $service->activePaths());
        Http::assertSentCount(2);
    }

    public function test_empty_pages_stop_pagination_when_the_path_list_shrinks(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'http://relay:9997/v3/paths/list' => Http::response([
                'pageCount' => 3,
                'items' => [['name' => 'camera-1-live', 'available' => true, 'online' => true]],
            ]),
            'http://relay:9997/v3/paths/list?page=1' => Http::response(['pageCount' => 1, 'items' => []]),
        ]);
        config()->set('mediamtx.api.base_url', 'http://relay:9997');
        config()->set('mediamtx.api.address', ':9997');

        $this->assertSame(['camera-1-live' => true], (new MediaMtxPathStatusService)->activePaths());
        Http::assertSentCount(2);
    }

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

        $service = new MediaMtxPathStatusService;

        $this->assertSame([
            'camera-12-live' => true,
        ], $service->activePaths());
    }
}
