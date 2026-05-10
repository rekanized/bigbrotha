<?php

namespace Database\Seeders;

use App\Models\AllowedLoginEmail;
use App\Models\User;
use App\Services\AuthenticationSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class AuthenticationSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $auth = app(AuthenticationSettingsService::class);
        $manualRequested = $this->shouldSeedManualAuth();
        $googleRequested = $this->shouldSeedGoogleAuth();

        if (!$manualRequested && !$googleRequested) {
            return;
        }

        $manualUser = $manualRequested ? $this->seedLocalAdmin() : null;
        $manualEnabled = $manualRequested
            ? $manualUser instanceof User
            : $auth->manualAuthEnabled();

        $googleConfiguration = $this->shouldSeedGoogleConfiguration()
            ? $this->googleConfiguration()
            : null;
        $googleVerification = $this->shouldSeedGoogleConfiguration()
            ? $this->googleVerification($googleConfiguration)
            : null;
        $googleEnabled = $this->seedInputProvided('SEED_GOOGLE_AUTH_ENABLED')
            ? (filter_var((string) env('SEED_GOOGLE_AUTH_ENABLED', 'false'), FILTER_VALIDATE_BOOL)
                && ($googleConfiguration ?? $auth->googleConfiguration())['configured']
                && ($googleVerification !== null || $auth->googleConfigurationVerified()))
            : $auth->googleAuthEnabled();

        $auth->saveConfiguration(
            $manualEnabled,
            $googleEnabled,
            $googleConfiguration,
            $googleVerification,
            markSetupComplete: $auth->isSetupComplete() || $manualEnabled || $googleEnabled,
        );
    }

    private function seedLocalAdmin(): ?User
    {
        $defaultEmail = app()->environment('local') ? 'admin@example.com' : '';
        $defaultPassword = app()->environment('local') ? 'ChangeMe123!' : '';

        $email = trim((string) env('SEED_ADMIN_EMAIL', $defaultEmail));
        $password = trim((string) env('SEED_ADMIN_PASSWORD', $defaultPassword));

        if ($email === '' || $password === '') {
            return null;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => (string) env('SEED_ADMIN_NAME', 'Seeded Admin'),
                'password' => $password,
                'is_admin' => true,
                'local_auth_enabled' => true,
                'password_updated_at' => now(),
            ],
        );

        AllowedLoginEmail::query()->updateOrCreate(
            ['email' => $email],
            ['added_by_user_id' => $user->getKey()],
        );

        return $user;
    }

    private function shouldSeedManualAuth(): bool
    {
        return app()->environment('local') || ($this->seedInputProvided('SEED_ADMIN_EMAIL') && $this->seedInputProvided('SEED_ADMIN_PASSWORD'));
    }

    private function shouldSeedGoogleAuth(): bool
    {
        return $this->seedInputProvided('SEED_GOOGLE_AUTH_ENABLED') || $this->shouldSeedGoogleConfiguration();
    }

    private function shouldSeedGoogleConfiguration(): bool
    {
        return $this->seedInputProvided('SEED_GOOGLE_CLIENT_ID')
            || $this->seedInputProvided('SEED_GOOGLE_CLIENT_SECRET')
            || $this->seedInputProvided('SEED_GOOGLE_REDIRECT_URI')
            || $this->seedInputProvided('SEED_GOOGLE_TESTED_EMAIL');
    }

    private function seedInputProvided(string $key): bool
    {
        return env($key) !== null;
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}|null
     */
    private function googleConfiguration(): ?array
    {
        $clientId = trim((string) env('SEED_GOOGLE_CLIENT_ID', ''));
        $clientSecret = trim((string) env('SEED_GOOGLE_CLIENT_SECRET', ''));
        $redirectUri = trim((string) env('SEED_GOOGLE_REDIRECT_URI', ''));

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            return null;
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ];
    }

    /**
     * @param  array{client_id: string, client_secret: string, redirect_uri: string}|null  $configuration
     * @return array{fingerprint: string, tested_at: string, tested_email: string}|null
     */
    private function googleVerification(?array $configuration): ?array
    {
        if ($configuration === null) {
            return null;
        }

        $testedEmail = trim((string) env('SEED_GOOGLE_TESTED_EMAIL', ''));

        if ($testedEmail === '') {
            return null;
        }

        return [
            'fingerprint' => app(AuthenticationSettingsService::class)->googleConfigurationFingerprint(
                $configuration['client_id'],
                $configuration['client_secret'],
                $configuration['redirect_uri'],
            ),
            'tested_at' => Carbon::now()->utc()->toIso8601String(),
            'tested_email' => $testedEmail,
        ];
    }
}