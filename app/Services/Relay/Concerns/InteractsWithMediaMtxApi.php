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

            if ($this->mediaMtxHttpRequestSuccessful($url) || $this->mediaMtxStreamRequestSuccessful($url)) {
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

            $payload = $this->mediaMtxStreamJson($url);

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

        if (!preg_match('/:(\d+)$/', $apiAddress, $matches)) {
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
            return Http::timeout(2)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private function mediaMtxHttpJson(string $url): ?array
    {
        try {
            $response = Http::timeout(2)->get($url);

            return $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function mediaMtxStreamRequestSuccessful(string $url): bool
    {
        $response = $this->mediaMtxStreamResponse($url);

        return $response !== null;
    }

    private function mediaMtxStreamJson(string $url): ?array
    {
        $response = $this->mediaMtxStreamResponse($url);

        if ($response === null) {
            return null;
        }

        $decoded = json_decode($response, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function mediaMtxStreamResponse(string $url): ?string
    {
        if (!function_exists('stream_context_create')) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if (!is_string($response) || !$this->mediaMtxResponseHeadersAreSuccessful($http_response_header ?? [])) {
            return null;
        }

        return $response;
    }

    /**
     * @param array<int, mixed> $headers
     */
    private function mediaMtxResponseHeadersAreSuccessful(array $headers): bool
    {
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }

            if (preg_match('#^HTTP/\\S+ 2\\d\\d#', $header) === 1) {
                return true;
            }
        }

        return false;
    }
}