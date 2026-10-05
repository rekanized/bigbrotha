<?php

namespace Tests\Feature;

use App\Services\Onvif\OnvifDeviceProbeService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class OnvifDeviceProbeServiceTest extends TestCase
{
    public function test_it_redacts_credentials_from_device_faults_and_excerpts(): void
    {
        $message = 'Rejected rtsp://operator:device-diagnostic-secret@192.0.2.44/stream?token=device-query-secret';

        $payloads = ['<html>'.$message.'</html>', '<Envelope><Fault><faultstring>'.$message.'</faultstring></Fault></Envelope>'];
        $responses = Http::fakeSequence();

        foreach ($payloads as $payload) {
            $responses->push($payload, 500);
        }

        foreach ($payloads as $index => $payload) {

            try {
                app(OnvifDeviceProbeService::class)->probe('http://192.0.2.44/onvif/device_service');
                $this->fail('The failed camera response should be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($index === 0 ? 'Response excerpt:' : 'rejected the request:', $exception->getMessage());
                $this->assertStringNotContainsString('device-diagnostic-secret', $exception->getMessage());
                $this->assertStringNotContainsString('device-query-secret', $exception->getMessage());
                $this->assertStringContainsString('rtsp://[redacted]@192.0.2.44', $exception->getMessage());
            }
        }
    }

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

        $result = app(OnvifDeviceProbeService::class)->probe('http://192.0.2.90/onvif/device_service', 'operator', 'secret');

        $this->assertSame('Axis', $result['manufacturer']);
        $this->assertSame('P3265-LVE', $result['model']);
        $this->assertSame('11.8.102', $result['firmware_version']);
        $this->assertSame('00408CA1BEEF', $result['serial_number']);
        $this->assertSame('rev-a', $result['hardware_id']);
        $this->assertSame(200, $result['http_status']);
        $this->assertTrue($result['authenticated']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://192.0.2.90/onvif/device_service'
                && str_contains($request->body(), 'http://www.onvif.org/ver10/device/wsdl/GetDeviceInformation')
                && str_contains($request->body(), '<a:To s:mustUnderstand="1">http://192.0.2.90/onvif/device_service</a:To>')
                && str_contains($request->body(), '<tds:GetDeviceInformation />')
                && str_contains($request->body(), '<wsse:Username>operator</wsse:Username>')
                && ! str_contains($request->body(), 'secret');
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

        app(OnvifDeviceProbeService::class)->probe('http://192.0.2.90/onvif/device_service', 'operator', 'wrong-password');
    }

    public function test_it_reads_primary_network_details_from_onvif_interfaces(): void
    {
        Http::fake([
            '*' => Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
    xmlns:tds="http://www.onvif.org/ver10/device/wsdl"
    xmlns:tt="http://www.onvif.org/ver10/schema">
    <s:Body>
        <tds:GetNetworkInterfacesResponse>
            <tds:NetworkInterfaces token="eth0">
                <tt:Info>
                    <tt:HwAddress>aa-bb-cc-dd-ee-ff</tt:HwAddress>
                </tt:Info>
                <tt:IPv4>
                    <tt:Config>
                        <tt:Manual>
                            <tt:Address>192.0.2.90</tt:Address>
                        </tt:Manual>
                    </tt:Config>
                </tt:IPv4>
            </tds:NetworkInterfaces>
        </tds:GetNetworkInterfacesResponse>
    </s:Body>
</s:Envelope>
XML, 200),
        ]);

        $result = app(OnvifDeviceProbeService::class)->fetchPrimaryNetworkDetails('http://192.0.2.90/onvif/device_service', 'operator', 'secret');

        $this->assertSame('192.0.2.90', $result['ipv4_address']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $result['mac_address']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://192.0.2.90/onvif/device_service'
                && str_contains($request->body(), 'http://www.onvif.org/ver10/device/wsdl/GetNetworkInterfaces')
                && str_contains($request->body(), '<tds:GetNetworkInterfaces />');
        });
    }
}
