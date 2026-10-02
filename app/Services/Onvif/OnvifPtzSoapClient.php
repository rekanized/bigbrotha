<?php

namespace App\Services\Onvif;

use App\Models\Camera;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class OnvifPtzSoapClient
{
    public function request(Camera $camera, string $url, string $namespace, string $operation, string $body): DOMXPath
    {
        $action = $namespace.'/'.$operation;
        $security = '';
        if (filled($camera->username)) {
            $created = now()->utc()->format('Y-m-d\TH:i:s\Z');
            $nonce = random_bytes(16);
            $digest = base64_encode(sha1($nonce.$created.($camera->password ?? ''), true));
            $encodedNonce = base64_encode($nonce);
            $username = $this->escape($camera->username);
            $security = <<<XML
<wsse:Security s:mustUnderstand="1" xmlns:wsse="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">
    <wsse:UsernameToken><wsse:Username>{$username}</wsse:Username>
    <wsse:Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">{$digest}</wsse:Password>
    <wsse:Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">{$encodedNonce}</wsse:Nonce>
    <wsu:Created>{$created}</wsu:Created></wsse:UsernameToken>
</wsse:Security>
XML;
        }
        $messageId = 'urn:uuid:'.Str::uuid();
        $escapedUrl = $this->escape($url);
        $envelope = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:a="http://www.w3.org/2005/08/addressing" xmlns:tds="http://www.onvif.org/ver10/device/wsdl" xmlns:trt="http://www.onvif.org/ver10/media/wsdl" xmlns:tptz="http://www.onvif.org/ver20/ptz/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">
    <s:Header><a:Action s:mustUnderstand="1">{$action}</a:Action><a:MessageID>{$messageId}</a:MessageID>
    <a:ReplyTo><a:Address>http://www.w3.org/2005/08/addressing/anonymous</a:Address></a:ReplyTo>
    <a:To s:mustUnderstand="1">{$escapedUrl}</a:To>{$security}</s:Header>
    <s:Body>{$body}</s:Body>
</s:Envelope>
XML;
        try {
            $request = Http::timeout(4)->connectTimeout(2)
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['SOAPAction' => $action]);
            $response = $request->withBody($envelope, 'application/soap+xml; charset=utf-8; action="'.$action.'"')->post($url);
            // Most devices accept WS-Security directly. Digest may send a separate
            // challenge probe, so enable it only when HTTP authentication is required.
            if ($response->status() === 401 && filled($camera->username)) {
                $response = $request->withDigestAuth($camera->username, $camera->password ?? '')
                    ->withBody($envelope, 'application/soap+xml; charset=utf-8; action="'.$action.'"')->post($url);
            }
        } catch (ConnectionException) {
            throw new RuntimeException('Unable to reach this camera’s ONVIF PTZ service.');
        } catch (RequestException) {
            throw new RuntimeException('The camera rejected the PTZ request. Check its ONVIF credentials and permissions.');
        }
        // Never surface device responses: they can contain credentials and private addresses.
        if (! $response->successful()) {
            throw new RuntimeException('The camera rejected the PTZ request. Check its ONVIF credentials and permissions.');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $valid = $document->loadXML($response->body(), LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $valid || $document->doctype !== null) {
            throw new RuntimeException('The camera returned an invalid ONVIF response.');
        }
        $xpath = new DOMXPath($document);
        if ($xpath->query('//*[local-name()="Fault"]')->length > 0) {
            throw new RuntimeException('The camera rejected the PTZ request. Check its ONVIF credentials and permissions.');
        }
        if ($xpath->query('//*[local-name()="'.$operation.'Response"]')->length === 0) {
            throw new RuntimeException('The camera returned an unexpected ONVIF response.');
        }

        return $xpath;
    }

    public function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
