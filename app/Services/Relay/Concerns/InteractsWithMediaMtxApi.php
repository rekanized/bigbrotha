<?php

namespace App\Services\Relay\Concerns;

use Illuminate\Support\Facades\Http;
use Throwable;

trait InteractsWithMediaMtxApi
{
    protected function mediaMtxApiRequestSuccessful(string $path): bool
    {
        foreach ($this->mediaMtxApiBaseUrls() as $baseUrl) {
            $url = $this->mediaMtxApiUrl($baseUrl, $path);

            if ($this->mediaMtxHttpRequestSuccessful($url)) {
                return true;
            }
        }

        return false;
    }

    protected function mediaMtxApiJson(string $path): ?array
    {
        foreach ($this->mediaMtxApiBaseUrls() as $baseUrl) {
            $url = $this->mediaMtxApiUrl($baseUrl, $path);
            $payload = $this->mediaMtxHttpJson($url);

            if (is_array($payload)) {
                return $payload;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function mediaMtxApiBaseUrls(): array
    {
        $baseUrls = [];
        $configuredBaseUrl = trim((string) config('mediamtx.api.base_url', ''));

        if ($configuredBaseUrl !== '') {
            $baseUrls[] = rtrim($configuredBaseUrl, '/');
        }

        $derivedBaseUrl = $this->derivedMediaMtxApiBaseUrl($configuredBaseUrl);

        if ($derivedBaseUrl !== null) {
            $baseUrls[] = $derivedBaseUrl;
        }

        return array_values(array_unique(array_filter($baseUrls, static fn (string $url): bool => $url !== '')));
    }

    protected function derivedMediaMtxApiBaseUrl(?string $configuredBaseUrl = null): ?string
    {
        $apiAddress = trim((string) config('mediamtx.api.address', ''));

        if (! preg_match('/:(\d+)$/', $apiAddress, $matches)) {
            return null;
        }

        $port = (int) ($matches[1] ?? 0);

        if ($port < 1) {
            return null;
        }

        $scheme = parse_url((string) $configuredBaseUrl, PHP_URL_SCHEME);
        $host = parse_url((string) $configuredBaseUrl, PHP_URL_HOST);

        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : 'http';
        $host = is_string($host) && $host !== '' ? $host : 'relay';

        return sprintf('%s://%s:%d', $scheme, $host, $port);
    }

    protected function mediaMtxApiUrl(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/').'/'.ltrim($path, '/');
    }

    private function mediaMtxHttpRequestSuccessful(string $url): bool
    {
        try {
            return Http::connectTimeout(1)->timeout(2)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function mediaMtxHttpJson(string $url): ?array
    {
        try {
            $response = Http::connectTimeout(1)->timeout(2)->get($url);

            $payload = $response->successful() ? $response->json() : null;

            return is_array($payload) ? $payload : null;
        } catch (Throwable) {
            return null;
        }
    }
}
