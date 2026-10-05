<?php

namespace App\Services\Onvif;

use App\Support\Logging\SensitiveDataRedactor;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OnvifDeviceProbeService
{
    private const GET_DEVICE_INFORMATION_ACTION = 'http://www.onvif.org/ver10/device/wsdl/GetDeviceInformation';

    private const GET_NETWORK_INTERFACES_ACTION = 'http://www.onvif.org/ver10/device/wsdl/GetNetworkInterfaces';

    /**
     * @return array<string, int|string|null>
     */
    public function probe(string $serviceUrl, ?string $username = null, ?string $password = null, int $timeoutSeconds = 5): array
    {
        $startedAt = microtime(true);

        $response = $this->sendSoapRequest(
            $serviceUrl,
            self::GET_DEVICE_INFORMATION_ACTION,
            '<tds:GetDeviceInformation />',
            $username,
            $password,
            $timeoutSeconds,
            'BigBrotha ONVIF Probe',
            'Unable to reach the ONVIF service URL. Confirm the protocol, host, port, path, and network access from this server.',
        );

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
        $parsedResponse = $this->parseResponse($response->body());

        if ($parsedResponse['fault'] !== null) {
            throw new RuntimeException('Authentication failed or the ONVIF service rejected the request: '.$parsedResponse['fault']);
        }

        if ($response->status() === 401) {
            throw new RuntimeException('Authentication failed with HTTP 401 from the ONVIF service.');
        }

        if ($response->failed()) {
            $message = 'The ONVIF service returned HTTP '.$response->status().'.';

            if ($parsedResponse['excerpt'] !== null) {
                $message .= ' Response excerpt: '.$parsedResponse['excerpt'];
            }

            throw new RuntimeException($message);
        }

        return [
            'service_url' => $serviceUrl,
            'authenticated' => $username !== null && $username !== '',
            'http_status' => $response->status(),
            'response_time_ms' => $durationMs,
            'manufacturer' => $parsedResponse['manufacturer'],
            'model' => $parsedResponse['model'],
            'firmware_version' => $parsedResponse['firmware_version'],
            'serial_number' => $parsedResponse['serial_number'],
            'hardware_id' => $parsedResponse['hardware_id'],
            'response_excerpt' => $parsedResponse['excerpt'],
        ];
    }

    /**
     * @return array{ipv4_address: string|null, mac_address: string|null}
     */
    public function fetchPrimaryNetworkDetails(string $serviceUrl, ?string $username = null, ?string $password = null, int $timeoutSeconds = 5): array
    {
        $response = $this->sendSoapRequest(
            $serviceUrl,
            self::GET_NETWORK_INTERFACES_ACTION,
            '<tds:GetNetworkInterfaces />',
            $username,
            $password,
            $timeoutSeconds,
            'BigBrotha ONVIF Probe',
            'Unable to read ONVIF network interface details from the camera.',
        );

        $fault = $this->extractFault($response->body());

        if ($fault !== null) {
            throw new RuntimeException('The ONVIF service rejected the network interface request: '.$fault);
        }

        if ($response->status() === 401) {
            throw new RuntimeException('Authentication failed with HTTP 401 from the ONVIF service.');
        }

        if ($response->failed()) {
            $message = 'The ONVIF service returned HTTP '.$response->status().' while reading network interfaces.';
            $excerpt = $this->excerpt($response->body());

            if ($excerpt !== null) {
                $message .= ' Response excerpt: '.$excerpt;
            }

            throw new RuntimeException($message);
        }

        return $this->parseNetworkInterfaces($response->body())[0] ?? [
            'ipv4_address' => null,
            'mac_address' => null,
        ];
    }

    private function sendSoapRequest(
        string $serviceUrl,
        string $action,
        string $body,
        ?string $username,
        ?string $password,
        int $timeoutSeconds,
        string $userAgent,
        string $connectionErrorMessage,
    ): Response {
        try {
            return Http::timeout($timeoutSeconds)
                ->connectTimeout($timeoutSeconds)
                ->withOptions(['verify' => false, 'allow_redirects' => false])
                ->accept('application/soap+xml, application/xml, text/xml')
                ->withHeaders([
                    'Content-Type' => 'application/soap+xml; charset=utf-8; action="'.$action.'"',
                    'SOAPAction' => $action,
                    'User-Agent' => $userAgent,
                ])
                ->withBody($this->buildEnvelope($serviceUrl, $action, $body, $username, $password), 'application/soap+xml; charset=utf-8')
                ->post($serviceUrl);
        } catch (ConnectionException $exception) {
            throw new RuntimeException($connectionErrorMessage, previous: $exception);
        }
    }

    private function buildEnvelope(string $serviceUrl, string $action, string $body, ?string $username, ?string $password): string
    {
        $messageId = 'urn:uuid:'.Str::uuid()->toString();
        $securityHeader = $this->buildSecurityHeader($username, $password);
        $escapedServiceUrl = $this->escapeXml($serviceUrl);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
    xmlns:a="http://www.w3.org/2005/08/addressing"
    xmlns:tds="http://www.onvif.org/ver10/device/wsdl">
    <s:Header>
        <a:Action s:mustUnderstand="1">{$action}</a:Action>
        <a:MessageID>{$messageId}</a:MessageID>
        <a:ReplyTo>
            <a:Address>http://www.w3.org/2005/08/addressing/anonymous</a:Address>
        </a:ReplyTo>
        <a:To s:mustUnderstand="1">{$escapedServiceUrl}</a:To>{$securityHeader}
    </s:Header>
    <s:Body>
        {$body}
    </s:Body>
</s:Envelope>
XML;
    }

    private function buildSecurityHeader(?string $username, ?string $password): string
    {
        if ($username === null || $username === '' || $password === null || $password === '') {
            return '';
        }

        $created = now()->utc()->format('Y-m-d\TH:i:s\Z');
        $nonceBytes = random_bytes(16);
        $nonce = base64_encode($nonceBytes);
        $digest = base64_encode(sha1($nonceBytes.$created.$password, true));

        return <<<XML

        <wsse:Security s:mustUnderstand="1"
            xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"
            xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
            <wsse:UsernameToken>
                <wsse:Username>{$this->escapeXml($username)}</wsse:Username>
                <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">{$digest}</wsse:Password>
                <wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">{$nonce}</wsse:Nonce>
                <wsu:Created>{$created}</wsu:Created>
            </wsse:UsernameToken>
        </wsse:Security>
XML;
    }

    /**
     * @return array<string, string|null>
     */
    private function parseResponse(string $payload): array
    {
        $xpath = $this->createXPath($payload);

        if ($xpath === null) {
            return [
                'fault' => null,
                'manufacturer' => null,
                'model' => null,
                'firmware_version' => null,
                'serial_number' => null,
                'hardware_id' => null,
                'excerpt' => $this->excerpt($payload),
            ];
        }

        return [
            'fault' => $this->extractFault($payload),
            'manufacturer' => $this->firstValue($xpath, ['//*[local-name()="Manufacturer"]']),
            'model' => $this->firstValue($xpath, ['//*[local-name()="Model"]']),
            'firmware_version' => $this->firstValue($xpath, ['//*[local-name()="FirmwareVersion"]']),
            'serial_number' => $this->firstValue($xpath, ['//*[local-name()="SerialNumber"]']),
            'hardware_id' => $this->firstValue($xpath, ['//*[local-name()="HardwareId"]']),
            'excerpt' => $this->excerpt($payload),
        ];
    }

    /**
     * @param  array<int, string>  $queries
     */
    private function firstValue(DOMXPath $xpath, array $queries, ?DOMNode $contextNode = null): ?string
    {
        foreach ($queries as $query) {
            $value = trim((string) $xpath->evaluate('string('.$query.')', $contextNode));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractFault(string $payload): ?string
    {
        $xpath = $this->createXPath($payload);

        if ($xpath === null) {
            return null;
        }

        $fault = $this->firstValue($xpath, [
            '//*[local-name()="Fault"]/*[local-name()="Reason"]/*[local-name()="Text"]',
            '//*[local-name()="Fault"]/*[local-name()="faultstring"]',
            '//*[local-name()="Reason"]/*[local-name()="Text"]',
        ]);

        return $fault !== null ? SensitiveDataRedactor::message($fault) : null;
    }

    /**
     * @return array<int, array{ipv4_address: string|null, mac_address: string|null}>
     */
    private function parseNetworkInterfaces(string $payload): array
    {
        $xpath = $this->createXPath($payload);

        if ($xpath === null) {
            return [];
        }

        $nodes = $xpath->query('//*[local-name()="NetworkInterfaces" or local-name()="NetworkInterface"]');

        if ($nodes === false) {
            return [];
        }

        $interfaces = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $interfaces[] = [
                'ipv4_address' => $this->normalizeIpv4Address($this->firstValue($xpath, [
                    './/*[local-name()="IPv4"]//*[local-name()="Config"]/*[local-name()="Manual"]/*[local-name()="Address"]',
                    './/*[local-name()="IPv4"]//*[local-name()="Config"]/*[local-name()="FromDHCP"]/*[local-name()="Address"]',
                    './/*[local-name()="IPv4"]//*[local-name()="Config"]/*[local-name()="LinkLocal"]/*[local-name()="Address"]',
                    './/*[local-name()="IPv4"]//*[local-name()="Address"]',
                ], $node)),
                'mac_address' => $this->normalizeMacAddress($this->firstValue($xpath, [
                    './/*[local-name()="Info"]/*[local-name()="HwAddress"]',
                    './/*[local-name()="HwAddress"]',
                    './/*[local-name()="MACAddress"]',
                ], $node)),
            ];
        }

        $interfaces = array_values(array_filter($interfaces, static fn (array $interface): bool => $interface['ipv4_address'] !== null || $interface['mac_address'] !== null));

        usort($interfaces, static function (array $left, array $right): int {
            $leftScore = ($left['ipv4_address'] !== null ? 2 : 0) + ($left['mac_address'] !== null ? 1 : 0);
            $rightScore = ($right['ipv4_address'] !== null ? 2 : 0) + ($right['mac_address'] !== null ? 1 : 0);

            return $rightScore <=> $leftScore;
        });

        return $interfaces;
    }

    private function createXPath(string $payload): ?DOMXPath
    {
        $document = new DOMDocument;
        $previousState = libxml_use_internal_errors(true);

        try {
            if (! @$document->loadXML($payload, LIBXML_NONET)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }

        return new DOMXPath($document);
    }

    private function normalizeIpv4Address(?string $value): ?string
    {
        if (! is_string($value) || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }

        return $value;
    }

    private function normalizeMacAddress(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $hex = strtoupper(preg_replace('/[^0-9A-F]/i', '', $value) ?? '');

        if (strlen($hex) !== 12) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }

    private function excerpt(string $payload): ?string
    {
        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($payload)) ?? '');

        if ($excerpt === '') {
            return null;
        }

        return Str::limit(SensitiveDataRedactor::message($excerpt), 240);
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}
