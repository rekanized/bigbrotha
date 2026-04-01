<?php

namespace App\Services\Discovery;

use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Str;
use RuntimeException;
use Socket;

class OnvifWsDiscoveryService
{
    private const MULTICAST_ADDRESS = '239.255.255.250';

    private const PORT = 3702;

    private const NETWORK_VIDEO_TRANSMITTER_TYPE = 'dn:NetworkVideoTransmitter';

    private const RECEIVE_TIMEOUT_MICROSECONDS = 50000;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function discover(int $timeoutMs = 3000): array
    {
        $sockets = [];

        try {
            foreach ($this->resolveProbeSourceAddresses() as $sourceAddress) {
                $socket = $this->createSocket($sourceAddress);
                $this->sendProbePayloads($socket);
                $sockets[] = $socket;
            }

            if ($sockets === []) {
                $socket = $this->createSocket(null);
                $this->sendProbePayloads($socket);
                $sockets[] = $socket;
            }

            $deadline = microtime(true) + ($timeoutMs / 1000);
            $devices = [];

            while (microtime(true) < $deadline) {
                foreach ($sockets as $socket) {
                    foreach ($this->receiveResponses($socket) as $device) {
                        $deviceKey = $device['endpoint_reference'] ?: ($device['service_url'] ?: $device['remote_ip']);
                        $devices[$deviceKey] = $device;
                    }
                }
            }
        } finally {
            foreach ($sockets as $socket) {
                socket_close($socket);
            }
        }

        ksort($devices);

        return array_values($devices);
    }

    private function createSocket(?string $sourceAddress): Socket
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

        if ($socket === false) {
            throw new RuntimeException('Unable to create UDP socket for WS-Discovery.');
        }

        socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($socket, $sourceAddress ?? '0.0.0.0', 0)) {
            throw new RuntimeException('Unable to bind the WS-Discovery socket.');
        }

        socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 2);
        socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => 0,
            'usec' => self::RECEIVE_TIMEOUT_MICROSECONDS,
        ]);

        return $socket;
    }

    private function sendProbePayloads(Socket $socket): void
    {
        foreach ($this->buildProbeEnvelopes() as $payload) {
            $sentBytes = @socket_sendto($socket, $payload, strlen($payload), 0, self::MULTICAST_ADDRESS, self::PORT);

            if ($sentBytes === false) {
                throw new RuntimeException('Unable to send the WS-Discovery probe. Check multicast network access.');
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function receiveResponses(Socket $socket): array
    {
        $buffer = '';
        $remoteIp = '';
        $remotePort = 0;
        $bytes = @socket_recvfrom($socket, $buffer, 65535, 0, $remoteIp, $remotePort);

        if ($bytes === false) {
            if ($this->isSocketTimeout(socket_last_error($socket))) {
                socket_clear_error($socket);

                return [];
            }

            throw new RuntimeException('Unable to receive WS-Discovery responses from the network.');
        }

        if ($bytes === 0 || $buffer === '') {
            return [];
        }

        return $this->parseResponse($buffer, $remoteIp, $remotePort);
    }

    /**
     * @return array<int, string>
     */
    private function buildProbeEnvelopes(): array
    {
        return [
            $this->buildProbeEnvelope(self::NETWORK_VIDEO_TRANSMITTER_TYPE),
            $this->buildProbeEnvelope(),
        ];
    }

    private function buildProbeEnvelope(?string $types = null): string
    {
        $messageId = 'uuid:'.Str::uuid()->toString();
        $typesBlock = $types === null ? '' : PHP_EOL.'            <d:Types>'.$types.'</d:Types>';

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<e:Envelope xmlns:e="http://www.w3.org/2003/05/soap-envelope"
    xmlns:w="http://schemas.xmlsoap.org/ws/2004/08/addressing"
    xmlns:d="http://schemas.xmlsoap.org/ws/2005/04/discovery"
    xmlns:dn="http://www.onvif.org/ver10/network/wsdl">
    <e:Header>
        <w:MessageID>{$messageId}</w:MessageID>
        <w:To>urn:schemas-xmlsoap-org:ws:2005:04:discovery</w:To>
        <w:Action>http://schemas.xmlsoap.org/ws/2005/04/discovery/Probe</w:Action>
    </e:Header>
    <e:Body>
        <d:Probe>{$typesBlock}
        </d:Probe>
    </e:Body>
</e:Envelope>
XML;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseResponse(string $payload, string $remoteIp, int $remotePort): array
    {
        $document = new DOMDocument();
        $previousState = libxml_use_internal_errors(true);

        try {
            if (!@$document->loadXML($payload)) {
                return [];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }

        $xpath = new DOMXPath($document);

        $matches = $xpath->query('//*[local-name()="ProbeMatch"]');

        if ($matches === false) {
            return [];
        }

        $devices = [];

        foreach ($matches as $match) {
            $xaddrs = $this->splitSpaceSeparatedList($this->evaluateString($xpath, './*[local-name()="XAddrs"]', $match));
            $serviceUrl = $xaddrs[0] ?? null;
            $parsedServiceUrl = is_string($serviceUrl) ? parse_url($serviceUrl) : false;
            $scopes = $this->splitSpaceSeparatedList($this->evaluateString($xpath, './*[local-name()="Scopes"]', $match));
            $types = $this->splitSpaceSeparatedList($this->evaluateString($xpath, './*[local-name()="Types"]', $match));
            $metadataVersion = (int) $this->evaluateString($xpath, './*[local-name()="MetadataVersion"]', $match);

            $devices[] = [
                'name' => $this->extractScopeValue($scopes, 'name')
                    ?? $this->extractScopeValue($scopes, 'hardware')
                    ?? ($parsedServiceUrl['host'] ?? null)
                    ?? $remoteIp,
                'hardware' => $this->extractScopeValue($scopes, 'hardware'),
                'location' => $this->extractScopeValue($scopes, 'location'),
                'remote_ip' => $remoteIp,
                'remote_port' => $remotePort,
                'endpoint_reference' => $this->stringOrNull($this->evaluateString($xpath, './*[local-name()="EndpointReference"]/*[local-name()="Address"]', $match)),
                'service_url' => $serviceUrl,
                'hostname' => is_array($parsedServiceUrl) ? ($parsedServiceUrl['host'] ?? null) : null,
                'scheme' => is_array($parsedServiceUrl) ? ($parsedServiceUrl['scheme'] ?? null) : null,
                'port' => is_array($parsedServiceUrl) ? ($parsedServiceUrl['port'] ?? null) : null,
                'path' => is_array($parsedServiceUrl) ? ($parsedServiceUrl['path'] ?? null) : null,
                'types' => $types,
                'scopes' => $scopes,
                'xaddrs' => $xaddrs,
                'metadata_version' => $metadataVersion > 0 ? $metadataVersion : null,
            ];
        }

        return $devices;
    }

    private function evaluateString(DOMXPath $xpath, string $query, DOMNode $contextNode): string
    {
        return (string) $xpath->evaluate('string('.$query.')', $contextNode);
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $interfaces
     * @return array<int, string>
     */
    private function resolveProbeSourceAddresses(?array $interfaces = null): array
    {
        $interfaces ??= function_exists('net_get_interfaces') ? @net_get_interfaces() : null;

        if (!is_array($interfaces)) {
            return [];
        }

        $privateAddresses = [];
        $fallbackAddresses = [];

        foreach ($interfaces as $interface) {
            if (($interface['up'] ?? false) !== true) {
                continue;
            }

            foreach ($interface['unicast'] ?? [] as $unicast) {
                if (($unicast['family'] ?? null) !== AF_INET) {
                    continue;
                }

                $address = $unicast['address'] ?? null;

                if (!is_string($address) || $this->shouldSkipSourceAddress($address, $unicast)) {
                    continue;
                }

                if ($this->isPrivateIpv4($address)) {
                    $this->pushUniqueAddress($privateAddresses, $address);

                    continue;
                }

                $this->pushUniqueAddress($fallbackAddresses, $address);
            }
        }

        return $privateAddresses !== [] ? $privateAddresses : $fallbackAddresses;
    }

    /**
     * @param  array<string, mixed>  $unicast
     */
    private function shouldSkipSourceAddress(string $address, array $unicast): bool
    {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        if ($address === '0.0.0.0' || str_starts_with($address, '127.') || str_starts_with($address, '169.254.')) {
            return true;
        }

        return ($unicast['netmask'] ?? null) === '255.255.255.255';
    }

    private function isPrivateIpv4(string $address): bool
    {
        return str_starts_with($address, '10.')
            || str_starts_with($address, '192.168.')
            || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $address) === 1;
    }

    /**
     * @param  array<int, string>  $addresses
     */
    private function pushUniqueAddress(array &$addresses, string $address): void
    {
        if (!in_array($address, $addresses, true)) {
            $addresses[] = $address;
        }
    }

    /**
     * @param  array<int, string>  $scopes
     */
    private function extractScopeValue(array $scopes, string $segment): ?string
    {
        $prefix = 'onvif://www.onvif.org/'.$segment.'/';

        foreach ($scopes as $scope) {
            if (!str_starts_with($scope, $prefix)) {
                continue;
            }

            return rawurldecode(substr($scope, strlen($prefix)));
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function splitSpaceSeparatedList(string $value): array
    {
        $parts = preg_split('/\s+/', trim($value)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    private function stringOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isSocketTimeout(int $errorCode): bool
    {
        return in_array($errorCode, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK, SOCKET_ETIMEDOUT], true);
    }
}