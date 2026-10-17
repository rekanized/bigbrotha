<?php

namespace Tests\Feature\Relay;

use App\Services\Relay\MediaMtxConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaMtxListenerConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_required_protocol_listeners_are_enabled(): void
    {
        config()->set('mediamtx.api.enabled', true);
        config()->set('mediamtx.webrtc.enabled', true);

        $config = app(MediaMtxConfigService::class)->buildConfig();

        $this->assertStringContainsString("api: true\n", $config);
        $this->assertStringContainsString("rtsp: true\n", $config);
        $this->assertStringContainsString("webrtc: true\n", $config);

        foreach (['rtmp', 'hls', 'srt', 'moq'] as $protocol) {
            $this->assertStringContainsString($protocol.": false\n", $config);
        }
    }

    public function test_configured_disabled_listeners_remain_disabled(): void
    {
        config()->set('mediamtx.api.enabled', false);
        config()->set('mediamtx.webrtc.enabled', false);

        $config = app(MediaMtxConfigService::class)->buildConfig();

        $this->assertStringContainsString("api: false\n", $config);
        $this->assertStringContainsString("webrtc: false\n", $config);
    }
}
