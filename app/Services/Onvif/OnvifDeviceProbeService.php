<?php

namespace App\Services\Onvif;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OnvifDeviceProbeService
{
    private const GET_DEVICE_INFORMATION_ACTION = 'http://www.onvif.org/ver10/device/wsdl/GetDeviceInformation';

    /**
     * @return array<string, int|string|null>
     */
    public function probe(string $serviceUrl, ?string $username = null, ?string $password = null, int $timeoutSeconds = 5): array
    {
        $startedAt = microtime(true);

        try {
            $response = Http::timeout($timeoutSeconds)
                ->connectTimeout($timeoutSeconds)
                ->withOptions(['verify' => false])
                ->accept('application/soap+xml, application/xml, text/xml')
                ->withHeaders([
                    'Content-Type' => 'application/soap+xml; charset=utf-8; action="'.self::GET_DEVICE_INFORMATION_ACTION.'"',
                    'SOAPAction' => self::GET_DEVICE_INFORMATION_ACTION,
                    'User-Agent' => 'BigBrotha ONVIF Probe',
                ])
                ->withBody($this->buildEnvelope($serviceUrl, $username, $password), 'application/soap+xml; charset=utf-8')
                ->post($serviceUrl);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach the ONVIF service URL. Confirm the protocol, host, port, path, and network access from this server.', previous: $exception);
        }

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

    private function buildEnvelope(string $serviceUrl, ?string $username, ?string $password): string
    {
        $action = self::GET_DEVICE_INFORMATION_ACTION;
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
        <tds:GetDeviceInformation />
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
        $document = new DOMDocument();
        $previousState = libxml_use_internal_errors(true);

        try {
            if (!@$document->loadXML($payload)) {
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
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }

        $xpath = new DOMXPath($document);

        return [
            'fault' => $this->firstValue($xpath, [
                '//*[local-name()="Fault"]/*[local-name()="Reason"]/*[local-name()="Text"]',
                '//*[local-name()="Fault"]/*[local-name()="faultstring"]',
                '//*[local-name()="Reason"]/*[local-name()="Text"]',
            ]),
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
    private function firstValue(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $value = trim((string) $xpath->evaluate('string('.$query.')'));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function excerpt(string $payload): ?string
    {
        $excerpt = trim(preg_replace('/\s+/', ' ', strip_tags($payload)) ?? '');

        if ($excerpt === '') {
            return null;
        }

        return Str::limit($excerpt, 240);
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    }
}