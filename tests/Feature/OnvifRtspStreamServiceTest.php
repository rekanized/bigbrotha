<?php

namespace Tests\Feature;

use App\Models\Camera;
use App\Services\Onvif\OnvifRtspStreamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnvifRtspStreamServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fetches_rtsp_stream_uris_from_onvif_media_profiles(): void
    {
        $camera = Camera::query()->create([
            'name' => 'Tapo C200',
            'local_ip' => '192.168.1.67',
            'http_port' => 2020,
            'onvif_port' => 2020,
            'rtsp_port' => 554,
            'onvif_path' => '/onvif/device_service',
            'username' => 'operator',
            'password' => 'secret',
            'supports_onvif' => true,
            'supports_rtsp' => false,
            'is_enabled' => true,
        ]);

        Http::fake(function (Request $request) {
            $body = $request->body();

            return match (true) {
                str_contains($body, '<tds:GetCapabilities>') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:tds="http://www.onvif.org/ver10/device/wsdl">
    <s:Body>
        <tds:GetCapabilitiesResponse>
            <tds:Capabilities>
                <tds:Media>
                    <tds:XAddr>http://192.168.1.67:2020/onvif/media_service</tds:XAddr>
                </tds:Media>
            </tds:Capabilities>
        </tds:GetCapabilitiesResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                str_contains($body, '<trt:GetProfiles />') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">
    <s:Body>
        <trt:GetProfilesResponse>
            <trt:Profiles token="profile_main">
                <tt:Name>MainStream</tt:Name>
                <tt:VideoEncoderConfiguration>
                    <tt:Encoding>H264</tt:Encoding>
                    <tt:Resolution>
                        <tt:Width>1920</tt:Width>
                        <tt:Height>1080</tt:Height>
                    </tt:Resolution>
                </tt:VideoEncoderConfiguration>
            </trt:Profiles>
            <trt:Profiles token="profile_sub">
                <tt:Name>SubStream</tt:Name>
                <tt:VideoEncoderConfiguration>
                    <tt:Encoding>H264</tt:Encoding>
                    <tt:Resolution>
                        <tt:Width>640</tt:Width>
                        <tt:Height>360</tt:Height>
                    </tt:Resolution>
                </tt:VideoEncoderConfiguration>
            </trt:Profiles>
        </trt:GetProfilesResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                str_contains($body, '<trt:ProfileToken>profile_main</trt:ProfileToken>') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl">
    <s:Body>
        <trt:GetStreamUriResponse>
            <trt:MediaUri>
                <trt:Uri>rtsp://192.168.1.67:554/stream1</trt:Uri>
            </trt:MediaUri>
        </trt:GetStreamUriResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                str_contains($body, '<trt:ProfileToken>profile_sub</trt:ProfileToken>') => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:trt="http://www.onvif.org/ver10/media/wsdl">
    <s:Body>
        <trt:GetStreamUriResponse>
            <trt:MediaUri>
                <trt:Uri>rtsp://192.168.1.67:554/stream2</trt:Uri>
            </trt:MediaUri>
        </trt:GetStreamUriResponse>
    </s:Body>
</s:Envelope>
XML, 200),
                default => Http::response('', 500),
            };
        });

        $result = app(OnvifRtspStreamService::class)->discover($camera);

        $this->assertSame('http://192.168.1.67:2020/onvif/media_service', $result['media_service_url']);
        $this->assertCount(2, $result['profiles']);
        $this->assertSame('MainStream', $result['profiles'][0]['name']);
        $this->assertSame('rtsp://192.168.1.67:554/stream1', $result['profiles'][0]['uri']);
        $this->assertSame('/stream1', $result['profiles'][0]['path']);

        Http::assertSentCount(4);
    }
}