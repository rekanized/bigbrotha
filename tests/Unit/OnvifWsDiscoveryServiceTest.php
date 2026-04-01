<?php

namespace Tests\Unit;

use App\Services\Discovery\OnvifWsDiscoveryService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class OnvifWsDiscoveryServiceTest extends TestCase
{
    #[Test]
    public function it_parses_probe_matches_across_ws_discovery_namespace_variants(): void
    {
        $payload = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"
    xmlns:wsa="http://www.w3.org/2005/08/addressing"
    xmlns:wsdd="http://docs.oasis-open.org/ws-dd/ns/discovery/2009/01"
    xmlns:dn="http://www.onvif.org/ver10/network/wsdl">
    <soap:Body>
        <wsdd:ProbeMatches>
            <wsdd:ProbeMatch>
                <wsa:EndpointReference>
                    <wsa:Address>urn:uuid:front-door-camera</wsa:Address>
                </wsa:EndpointReference>
                <wsdd:Types>dn:NetworkVideoTransmitter</wsdd:Types>
                <wsdd:Scopes>onvif://www.onvif.org/name/Front%20Door onvif://www.onvif.org/hardware/Model%20A</wsdd:Scopes>
                <wsdd:XAddrs>http://192.168.1.90/onvif/device_service</wsdd:XAddrs>
                <wsdd:MetadataVersion>3</wsdd:MetadataVersion>
            </wsdd:ProbeMatch>
        </wsdd:ProbeMatches>
    </soap:Body>
</soap:Envelope>
XML;

        $devices = $this->invokeServiceMethod('parseResponse', [$payload, '192.168.1.90', 3702]);

        $this->assertCount(1, $devices);
        $this->assertSame('Front Door', $devices[0]['name']);
        $this->assertSame('Model A', $devices[0]['hardware']);
        $this->assertSame('urn:uuid:front-door-camera', $devices[0]['endpoint_reference']);
        $this->assertSame('http://192.168.1.90/onvif/device_service', $devices[0]['service_url']);
        $this->assertSame(3, $devices[0]['metadata_version']);
    }

    #[Test]
    public function it_prefers_private_broadcast_capable_source_addresses_for_discovery(): void
    {
        $interfaces = [
            'lo' => [
                'up' => true,
                'unicast' => [
                    ['family' => AF_INET, 'address' => '127.0.0.1', 'netmask' => '255.0.0.0'],
                ],
            ],
            'ens160' => [
                'up' => true,
                'unicast' => [
                    ['family' => AF_INET, 'address' => '192.168.1.222', 'netmask' => '255.255.255.0', 'broadcast' => '192.168.1.255'],
                ],
            ],
            'tailscale0' => [
                'up' => true,
                'unicast' => [
                    ['family' => AF_INET, 'address' => '100.107.64.125', 'netmask' => '255.255.255.255', 'ptp' => '100.107.64.125'],
                ],
            ],
        ];

        $addresses = $this->invokeServiceMethod('resolveProbeSourceAddresses', [$interfaces]);

        $this->assertSame(['192.168.1.222'], $addresses);
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    private function invokeServiceMethod(string $method, array $arguments): mixed
    {
        $service = new OnvifWsDiscoveryService();
        $reflectionMethod = new ReflectionMethod($service, $method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($service, $arguments);
    }
}