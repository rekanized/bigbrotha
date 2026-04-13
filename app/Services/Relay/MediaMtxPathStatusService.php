<?php

namespace App\Services\Relay;

use Illuminate\Support\Facades\Http;
use Throwable;

class MediaMtxPathStatusService
{
    /**
     * @var array<string, true>|null
     */
    private ?array $activePaths = null;

    /**
     * @return array<string, true>
     */
    public function activePaths(): array
    {
        if ($this->activePaths !== null) {
            return $this->activePaths;
        }

        $baseUrl = rtrim((string) config('mediamtx.api.base_url', ''), '/');

        if ($baseUrl === '') {
            return $this->activePaths = [];
        }

        try {
            $response = Http::timeout(2)->get($baseUrl.'/v3/paths/list');

            if (!$response->successful()) {
                return $this->activePaths = [];
            }

            $items = $response->json('items');

            if (!is_array($items)) {
                return $this->activePaths = [];
            }

            $paths = [];

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $name = $this->stringOrNull($item['name'] ?? null);

                if ($name === null || !((bool) ($item['ready'] ?? false) && (bool) ($item['online'] ?? false))) {
                    continue;
                }

                $paths[$name] = true;
            }

            return $this->activePaths = $paths;
        } catch (Throwable) {
            return $this->activePaths = [];
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}