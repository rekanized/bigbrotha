<?php

namespace Tests\Feature;

use App\Services\Onvif\OnvifDeviceProbeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OnvifDeviceProbeServiceTest extends TestCase
{
    public function test_it_returns_device_information_from_a_manual_probe(): void
    {
        Http::fake([
            '*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
    xmlns:tds="http://www.onvif.org/ver10/device/wsdl">
    <s:Body>
        <tds:GetDeviceInformationResponse>
            <tds:Manufacturer>Axis</tds:Manufacturer>
            <tds:Model>P3265-LVE</tds:Model>
            <tds:FirmwareVersion>11.8.102</tds:FirmwareVersion>
            <tds:SerialNumber>00408CA1BEEF</tds:SerialNumber>
            <tds:HardwareId>rev-a</tds:HardwareId>
        </tds:GetDeviceInformationResponse>
    </s:Body>
</s:Envelope>
XML, 200),
        ]);

        $result = app(OnvifDeviceProbeService::class)->probe('http://192.168.1.90/onvif/device_service', 'operator', 'secret');

        $this->assertSame('Axis', $result['manufacturer']);
        $this->assertSame('P3265-LVE', $result['model']);
        $this->assertSame('11.8.102', $result['firmware_version']);
        $this->assertSame('00408CA1BEEF', $result['serial_number']);
        $this->assertSame('rev-a', $result['hardware_id']);
        $this->assertSame(200, $result['http_status']);
        $this->assertTrue($result['authenticated']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://192.168.1.90/onvif/device_service'
                && str_contains($request->body(), 'http://www.onvif.org/ver10/device/wsdl/GetDeviceInformation')
                && str_contains($request->body(), '<a:To s:mustUnderstand="1">http://192.168.1.90/onvif/device_service</a:To>')
                && str_contains($request->body(), '<tds:GetDeviceInformation />')
                && str_contains($request->body(), '<wsse:Username>operator</wsse:Username>')
                && !str_contains($request->body(), 'secret');
        });
    }

    public function test_it_surfaces_onvif_faults_from_the_manual_probe(): void
    {
        Http::fake([
            '*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope">
    <s:Body>
        <s:Fault>
            <s:Code>
                <s:Value>s:Sender</s:Value>
            </s:Code>
            <s:Reason>
                <s:Text xml:lang="en">NotAuthorized</s:Text>
            </s:Reason>
        </s:Fault>
    </s:Body>
</s:Envelope>
XML, 500),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Authentication failed or the ONVIF service rejected the request: NotAuthorized');

        app(OnvifDeviceProbeService::class)->probe('http://192.168.1.90/onvif/device_service', 'operator', 'wrong-password');
    }
}