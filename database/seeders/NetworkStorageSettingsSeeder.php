<?php

namespace Database\Seeders;

use App\Services\ApplicationSettingsService;
use Illuminate\Database\Seeder;

class NetworkStorageSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (!$this->shouldSeedNetworkStorage()) {
            return;
        }

        $settings = app(ApplicationSettingsService::class);
        $current = $settings->networkStorageSettings();
        $defaultPath = app()->environment('local') ? '//fileserver/cameras' : '';
        $path = $this->seedInputProvided('SEED_SMB_PATH')
            ? trim((string) env('SEED_SMB_PATH', $defaultPath))
            : (string) ($current['path'] ?? '');
        $username = $this->seedInputProvided('SEED_SMB_USERNAME')
            ? trim((string) env('SEED_SMB_USERNAME', ''))
            : (string) ($current['username'] ?? '');
        $passwordProvided = $this->seedInputProvided('SEED_SMB_PASSWORD');
        $password = $passwordProvided ? trim((string) env('SEED_SMB_PASSWORD', '')) : null;
        $enabled = $this->seedInputProvided('SEED_SMB_ENABLED')
            ? filter_var((string) env('SEED_SMB_ENABLED', 'false'), FILTER_VALIDATE_BOOL)
            : (bool) ($current['enabled'] ?? false);

        $settings->saveNetworkStorageSettings(
            $enabled,
            $path !== '' ? $path : null,
            $username !== '' ? $username : null,
            $password !== '' ? $password : null,
            preserveExistingPassword: !$passwordProvided,
        );
    }

    private function shouldSeedNetworkStorage(): bool
    {
        return $this->seedInputProvided('SEED_SMB_ENABLED')
            || $this->seedInputProvided('SEED_SMB_PATH')
            || $this->seedInputProvided('SEED_SMB_USERNAME')
            || $this->seedInputProvided('SEED_SMB_PASSWORD');
    }

    private function seedInputProvided(string $key): bool
    {
        return env($key) !== null;
    }
}