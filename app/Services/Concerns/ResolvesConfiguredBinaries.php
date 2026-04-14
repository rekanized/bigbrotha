<?php

namespace App\Services\Concerns;

use Symfony\Component\Process\ExecutableFinder;

trait ResolvesConfiguredBinaries
{
    /**
     * @param  array<int, mixed>  $candidates
     */
    protected function resolveBinary(array $candidates): ?string
    {
        $finder = new ExecutableFinder();

        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            if (str_contains($candidate, DIRECTORY_SEPARATOR)) {
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }

                continue;
            }

            $resolved = $finder->find($candidate);

            if (is_string($resolved) && $resolved !== '') {
                return $resolved;
            }
        }

        return null;
    }
}