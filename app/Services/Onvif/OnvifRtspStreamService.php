<?php

namespace App\Services\Onvif;

use App\Models\Camera;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OnvifRtspStreamService
{
    private const GET_CAPABILITIES_ACTION = 'http://www.onvif.org/ver10/device/wsdl/GetCapabilities';

    private const GET_PROFILES_ACTION = 'http://www.onvif.org/ver10/media/wsdl/GetProfiles';

    private const GET_STREAM_URI_ACTION = 'http://www.onvif.org/ver10/media/wsdl/GetStreamUri';

    /**
     * @return array{device_service_url: string, media_service_url: string, profiles: array<int, array<string, string|null>>}
     */
    public function discover(Camera $camera, int $timeoutSeconds = 5): array
    {
        $deviceServiceUrl = $camera->onvifEndpoint();

        if ($deviceServiceUrl === null) {
            throw new RuntimeException('This camera does not have a usable ONVIF device service URL yet.');
        }

        $credentials = [$camera->username, $camera->password];
        $capabilitiesPayload = $this->sendSoapRequest(
            $deviceServiceUrl,
            self::GET_CAPABILITIES_ACTION,
            '<tds:GetCapabilities><tds:Category>Media</tds:Category></tds:GetCapabilities>',
            [
                'tds' => 'http://www.onvif.org/ver10/device/wsdl',
            ],
            ...$credentials,
            timeoutSeconds: $timeoutSeconds,
        );

        $mediaServiceUrl = $this->parseMediaServiceUrl($capabilitiesPayload) ?? $deviceServiceUrl;
        $profilesPayload = $this->sendSoapRequest(
            $mediaServiceUrl,
            self::GET_PROFILES_ACTION,
            '<trt:GetProfiles />',
            [
                'trt' => 'http://www.onvif.org/ver10/media/wsdl',
                'tt' => 'http://www.onvif.org/ver10/schema',
            ],
            ...$credentials,
            timeoutSeconds: $timeoutSeconds,
        );

        $profiles = [];

        foreach ($this->parseProfiles($profilesPayload) as $profile) {
            $token = $profile['token'] ?? null;

            if (!is_string($token) || $token === '') {
                continue;
            }

            $streamUriPayload = $this->sendSoapRequest(
                $mediaServiceUrl,
                self::GET_STREAM_URI_ACTION,
                $this->buildGetStreamUriBody($token),
                [
                    'trt' => 'http://www.onvif.org/ver10/media/wsdl',
                    'tt' => 'http://www.onvif.org/ver10/schema',
                ],
                ...$credentials,
                timeoutSeconds: $timeoutSeconds,
            );

            $streamUri = $this->parseStreamUri($streamUriPayload);

            if ($streamUri === null) {
                continue;
            }

            $profiles[] = array_merge($profile, [
                'uri' => $streamUri,
                'path' => $this->parsePathFromUri($streamUri),
            ]);
        }

        return [
            'device_service_url' => $deviceServiceUrl,
            'media_service_url' => $mediaServiceUrl,
            'profiles' => $profiles,
        ];
    }

    /**
     * @param  array<string, string>  $namespaces
     */
    private function sendSoapRequest(
        string $serviceUrl,
        string $action,
        string $body,
        array $namespaces,
        ?string $username = null,
        ?string $password = null,
        int $timeoutSeconds = 5,
    ): string {
        try {
            $response = Http::timeout($timeoutSeconds)
                ->connectTimeout($timeoutSeconds)
                ->withOptions(['verify' => false])
                ->accept('application/soap+xml, application/xml, text/xml')
                ->withHeaders([
                    'Content-Type' => 'application/soap+xml; charset=utf-8; action="'.$action.'"',
                    'SOAPAction' => $action,
                    'User-Agent' => 'BigBrotha ONVIF Media Probe',
                ])
                ->withBody($this->buildEnvelope($serviceUrl, $action, $body, $namespaces, $username, $password), 'application/soap+xml; charset=utf-8')
                ->post($serviceUrl);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach the ONVIF media service for this camera.', previous: $exception);
        }

        $fault = $this->extractFault($response->body());

        if ($fault !== null) {
            throw new RuntimeException('The ONVIF media service rejected the request: '.$fault);
        }

        if ($response->failed()) {
            $message = 'The ONVIF media service returned HTTP '.$response->status().'.';
            $excerpt = $this->excerpt($response->body());

            if ($excerpt !== null) {
                $message .= ' Response excerpt: '.$excerpt;
            }

            throw new RuntimeException($message);
        }

        return $response->body();
    }

    /**
     * @param  array<string, string>  $namespaces
     */
    private function buildEnvelope(
        string $serviceUrl,
        string $action,
        string $body,
        array $namespaces,
        ?string $username,
        ?string $password,
    ): string {
        $namespaceString = '';

        foreach ($namespaces as $prefix => $uri) {
            $namespaceString .= PHP_EOL.'    xmlns:'.$prefix.'="'.$uri.'"';
        }

        $messageId = 'urn:uuid:'.Str::uuid()->toString();
        $securityHeader = $this->buildSecurityHeader($username, $password);
        $escapedServiceUrl = $this->escapeXml($serviceUrl);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
    xmlns:a="http://www.w3.org/2005/08/addressing"{$namespaceString}>
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

    private function buildGetStreamUriBody(string $profileToken): string
    {
        $escapedToken = $this->escapeXml($profileToken);

        return <<<XML
<trt:GetStreamUri>
    <trt:StreamSetup>
        <tt:Stream>RTP-Unicast</tt:Stream>
        <tt:Transport>
            <tt:Protocol>RTSP</tt:Protocol>
        </tt:Transport>
    </trt:StreamSetup>
    <trt:ProfileToken>{$escapedToken}</trt:ProfileToken>
</trt:GetStreamUri>
XML;
    }

    private function parseMediaServiceUrl(string $payload): ?string
    {
        $xpath = $this->createXPath($payload);

        if ($xpath === null) {
            return null;
        }

        foreach ([
            '//*[local-name()="Capabilities"]//*[local-name()="Media"]/*[local-name()="XAddr"]',
            '//*[local-name()="Capabilities"]//*[local-name()="Media2"]/*[local-name()="XAddr"]',
        ] as $query) {
            $value = trim((string) $xpath->evaluate('string('.$query.')'));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function parseProfiles(string $payload): array
    {
        $xpath = $this->createXPath($payload);

        if ($xpath === null) {
            return [];
        }

        $nodes = $xpath->query('//*[local-name()="Profiles" or local-name()="Profile"]');

        if ($nodes === false) {
            return [];
        }

        $profiles = [];

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $videoEncoder = './*[local-name()="VideoEncoderConfiguration"]';
            $width = trim((string) $xpath->evaluate('string('.$videoEncoder.'/*[local-name()="Resolution"]/*[local-name()="Width"])', $node));
            $height = trim((string) $xpath->evaluate('string('.$videoEncoder.'/*[local-name()="Resolution"]/*[local-name()="Height"])', $node));

            $profiles[] = [
                'token' => $this->stringOrNull($node->getAttribute('token')),
                'name' => $this->stringOrNull((string) $xpath->evaluate('string(./*[local-name()="Name"])', $node)),
                'encoding' => $this->stringOrNull((string) $xpath->evaluate('string('.$videoEncoder.'/*[local-name()="Encoding"])', $node)),
                'resolution' => $width !== '' && $height !== '' ? $width.'x'.$height : null,
                'uri' => null,
                'path' => null,
            ];
        }

        return $profiles;
    }

    private function parseStreamUri(string $payload): ?string
    {
        $xpath = $this->createXPath($payload);

        if ($xpath === null) {
            return null;
        }

        return $this->stringOrNull((string) $xpath->evaluate('string(//*[local-name()="MediaUri"]/*[local-name()="Uri"])'));
    }

    private function extractFault(string $payload): ?string
    {
        $xpath = $this->createXPath($payload);

        if ($xpath === null) {
            return null;
        }

        foreach ([
            '//*[local-name()="Fault"]/*[local-name()="Reason"]/*[local-name()="Text"]',
            '//*[local-name()="Fault"]/*[local-name()="faultstring"]',
            '//*[local-name()="Reason"]/*[local-name()="Text"]',
        ] as $query) {
            $value = trim((string) $xpath->evaluate('string('.$query.')'));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function createXPath(string $payload): ?DOMXPath
    {
        $document = new DOMDocument();
        $previousState = libxml_use_internal_errors(true);

        try {
            if (!@$document->loadXML($payload)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousState);
        }

        return new DOMXPath($document);
    }

    private function parsePathFromUri(string $uri): ?string
    {
        $parts = parse_url($uri);

        if (!is_array($parts)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path === '' && $query === '' ? null : $path.$query;
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

    private function stringOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
