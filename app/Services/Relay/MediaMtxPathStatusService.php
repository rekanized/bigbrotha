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

        $paths = [];
        $pageCount = 1;

        for ($page = 0; $page < $pageCount; $page++) {
            $payload = $this->mediaMtxApiJson('/v3/paths/list'.($page > 0 ? '?page='.$page : ''));
            $items = $payload['items'] ?? null;

            if (! is_array($items) || $items === []) {
                break;
            }

            // The API defaults to 100 paths per page. Use the first response's
            // page count so a growing camera fleet cannot prolong this read.
            if ($page === 0) {
                $pageCount = max(1, (int) ($payload['pageCount'] ?? 1));
            }

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $name = $this->stringOrNull($item['name'] ?? null);
                $available = (bool) ($item['available'] ?? $item['ready'] ?? false);

                if ($name === null || ! ($available && (bool) ($item['online'] ?? false))) {
                    continue;
                }

                $paths[$name] = true;
            }
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
