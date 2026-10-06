<?php

namespace App\Support;

use RuntimeException;

class CameraUrl
{
    public static function assertRtsp(string $url): void
    {
        self::assertScheme($url, ['rtsp', 'rtsps']);
    }

    public static function assertHttp(string $url): void
    {
        $parts = self::assertScheme($url, ['http', 'https']);

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('ONVIF addresses must not contain credentials. Use the camera credential fields.');
        }
    }

    public static function assertRtspFromDevice(string $url, string $deviceUrl): void
    {
        self::assertRtsp($url);
        self::assertHttp($deviceUrl);

        if (strcasecmp((string) parse_url($url, PHP_URL_HOST), (string) parse_url($deviceUrl, PHP_URL_HOST)) !== 0) {
            throw new RuntimeException('The camera returned an RTSP address outside its configured host.');
        }
    }

    public static function assertSameHost(string $url, string $deviceUrl): void
    {
        self::assertHttp($url);
        self::assertHttp($deviceUrl);

        if (strcasecmp((string) parse_url($url, PHP_URL_HOST), (string) parse_url($deviceUrl, PHP_URL_HOST)) !== 0
            || (strtolower((string) parse_url($deviceUrl, PHP_URL_SCHEME)) === 'https'
                && strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https')) {
            throw new RuntimeException('The camera returned an ONVIF address outside its configured host or downgraded HTTPS.');
        }
    }

    private static function assertScheme(string $url, array $schemes): array
    {
        $parts = parse_url($url);

        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || ! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), $schemes, true)
            || empty($parts['host'])
            || isset($parts['fragment'])) {
            throw new RuntimeException('The camera address has an invalid protocol or format.');
        }

        return $parts;
    }

    public static function withoutCredentials(string $url): string
    {
        return preg_replace('#\A([a-z][a-z0-9+.-]*://)[^/\s?\#]*@#i', '$1', $url) ?? $url;
    }
}
