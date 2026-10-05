<?php

namespace App\Services\Relay;

use App\Services\Relay\Concerns\InteractsWithMediaMtxApi;

class MediaMtxPathStatusService
{
    use InteractsWithMediaMtxApi;

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

        if ($this->mediaMtxApiBaseUrls() === []) {
            return $this->activePaths = [];
        }

        $payload = $this->mediaMtxApiJson('/v3/paths/list');

        if (! is_array($payload)) {
            return $this->activePaths = [];
        }

        $items = $payload['items'] ?? null;

        if (! is_array($items)) {
            return $this->activePaths = [];
        }

        $paths = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $name = $this->stringOrNull($item['name'] ?? null);

            if ($name === null || ! ((bool) ($item['ready'] ?? false) && (bool) ($item['online'] ?? false))) {
                continue;
            }

            $paths[$name] = true;
        }

        return $this->activePaths = $paths;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
