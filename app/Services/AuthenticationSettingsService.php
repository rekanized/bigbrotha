<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Throwable;

class AuthenticationSettingsService
{
    public const SETTING_SETUP_COMPLETE = 'auth_setup_complete';

    public const SETTING_MANUAL_AUTH_ENABLED = 'auth_manual_enabled';

    public const SETTING_GOOGLE_AUTH_ENABLED = 'auth_google_enabled';

    public const SETTING_GOOGLE_CLIENT_ID = 'auth_google_client_id';

    public const SETTING_GOOGLE_CLIENT_SECRET = 'auth_google_client_secret';

    public const SETTING_GOOGLE_REDIRECT_URI = 'auth_google_redirect_uri';

    public const SETTING_GOOGLE_TESTED_FINGERPRINT = 'auth_google_tested_fingerprint';

    public const SETTING_GOOGLE_TESTED_AT = 'auth_google_tested_at';

    public const SETTING_GOOGLE_TESTED_EMAIL = 'auth_google_tested_email';

    /**
     * @var array<string, string>|null
     */
    private ?array $loadedSettings = null;

    public function __construct(private readonly ApplicationSettingStore $settingStore) {}

    public function apply(): void
    {
        $google = $this->googleConfiguration();

        if (! $google['configured']) {
            return;
        }

        config()->set('services.google.client_id', $google['client_id']);
        config()->set('services.google.client_secret', $google['client_secret']);
        config()->set('services.google.redirect', $google['redirect_uri']);
    }

    public function isSetupComplete(): bool
    {
        if ($this->booleanSettingExists(self::SETTING_SETUP_COMPLETE)) {
            return $this->booleanSetting(self::SETTING_SETUP_COMPLETE, false);
        }

        if (! $this->settingStore->available()) {
            return false;
        }

        try {
            return User::query()->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function manualAuthEnabled(): bool
    {
        if ($this->booleanSettingExists(self::SETTING_MANUAL_AUTH_ENABLED)) {
            return $this->booleanSetting(self::SETTING_MANUAL_AUTH_ENABLED, false);
        }

        if (! $this->settingStore->available()) {
            return false;
        }

        try {
            return User::query()->where('local_auth_enabled', true)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function googleAuthEnabled(): bool
    {
        $requested = $this->booleanSettingExists(self::SETTING_GOOGLE_AUTH_ENABLED)
            ? $this->booleanSetting(self::SETTING_GOOGLE_AUTH_ENABLED, false)
            : $this->legacyGoogleConfigurationPresent();

        return $requested && $this->googleConfigurationVerified();
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string, configured: bool, verified: bool, tested_at: ?string, tested_email: ?string, fingerprint: ?string}
     */
    public function googleConfiguration(): array
    {
        $clientId = $this->nullableString($this->setting(self::SETTING_GOOGLE_CLIENT_ID))
            ?? $this->nullableString((string) config('services.google.client_id'))
            ?? '';

        $clientSecret = $this->resolveStoredSecret(self::SETTING_GOOGLE_CLIENT_SECRET)
            ?? $this->nullableString((string) config('services.google.client_secret'))
            ?? '';

        $redirectUri = $this->nullableString($this->setting(self::SETTING_GOOGLE_REDIRECT_URI))
            ?? $this->nullableString((string) config('services.google.redirect'))
            ?? '';

        $configured = $clientId !== '' && $clientSecret !== '' && $redirectUri !== '';
        $fingerprint = $configured ? $this->googleConfigurationFingerprint($clientId, $clientSecret, $redirectUri) : null;
        $testedFingerprint = $this->nullableString($this->setting(self::SETTING_GOOGLE_TESTED_FINGERPRINT));
        $usesStoredSettings = $this->settingExists(self::SETTING_GOOGLE_CLIENT_ID)
            || $this->settingExists(self::SETTING_GOOGLE_CLIENT_SECRET)
            || $this->settingExists(self::SETTING_GOOGLE_REDIRECT_URI);

        $verified = $configured && ($usesStoredSettings
            ? ($testedFingerprint !== null && hash_equals($testedFingerprint, (string) $fingerprint))
            : $this->legacyGoogleConfigurationPresent());

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'configured' => $configured,
            'verified' => $verified,
            'tested_at' => $this->nullableString($this->setting(self::SETTING_GOOGLE_TESTED_AT)),
            'tested_email' => $this->nullableString($this->setting(self::SETTING_GOOGLE_TESTED_EMAIL)),
            'fingerprint' => $fingerprint,
        ];
    }

    public function googleConfigurationVerified(): bool
    {
        return $this->googleConfiguration()['verified'];
    }

    public function googleConfigurationFingerprint(string $clientId, string $clientSecret, string $redirectUri): string
    {
        return hash('sha256', trim($clientId).'|'.trim($clientSecret).'|'.trim($redirectUri));
    }

    public function saveConfiguration(
        bool $manualEnabled,
        bool $googleEnabled,
        ?array $googleConfiguration = null,
        ?array $googleVerification = null,
        bool $markSetupComplete = true,
    ): void {
        $this->storePlainSetting(self::SETTING_MANUAL_AUTH_ENABLED, $manualEnabled ? '1' : '0');
        $this->storePlainSetting(self::SETTING_GOOGLE_AUTH_ENABLED, $googleEnabled ? '1' : '0');

        if ($googleConfiguration !== null) {
            $clientId = $this->nullableString($googleConfiguration['client_id'] ?? null) ?? '';
            $clientSecret = $this->nullableString($googleConfiguration['client_secret'] ?? null) ?? '';
            $redirectUri = $this->nullableString($googleConfiguration['redirect_uri'] ?? null) ?? '';

            $this->storePlainSetting(self::SETTING_GOOGLE_CLIENT_ID, $clientId !== '' ? $clientId : null);
            $this->storeEncryptedSetting(self::SETTING_GOOGLE_CLIENT_SECRET, $clientSecret !== '' ? $clientSecret : null);
            $this->storePlainSetting(self::SETTING_GOOGLE_REDIRECT_URI, $redirectUri !== '' ? $redirectUri : null);
        }

        if ($googleVerification !== null) {
            $this->storePlainSetting(self::SETTING_GOOGLE_TESTED_FINGERPRINT, $this->nullableString($googleVerification['fingerprint'] ?? null));
            $this->storePlainSetting(self::SETTING_GOOGLE_TESTED_AT, $this->nullableString($googleVerification['tested_at'] ?? null));
            $this->storePlainSetting(self::SETTING_GOOGLE_TESTED_EMAIL, $this->nullableString($googleVerification['tested_email'] ?? null));
        }

        if ($markSetupComplete) {
            $this->storePlainSetting(self::SETTING_SETUP_COMPLETE, '1');
        }

        $this->settingStore->forget();
        $this->loadedSettings = null;
        $this->apply();
    }

    public function adminCanAuthenticateWith(bool $manualEnabled, bool $googleEnabled): bool
    {
        return ($manualEnabled && $this->localAdminExists()) || ($googleEnabled && $this->googleAdminExists());
    }

    public function localAdminExists(): bool
    {
        if (! $this->settingStore->available()) {
            return false;
        }

        try {
            return User::query()
                ->where('is_admin', true)
                ->where('local_auth_enabled', true)
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public function googleAdminExists(): bool
    {
        if (! $this->settingStore->available()) {
            return false;
        }

        try {
            return User::query()
                ->where('is_admin', true)
                ->whereNotNull('google_id')
                ->where('google_id', '!=', '')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function legacyGoogleConfigurationPresent(): bool
    {
        return $this->nullableString((string) config('services.google.client_id')) !== null
            && $this->nullableString((string) config('services.google.client_secret')) !== null
            && $this->nullableString((string) config('services.google.redirect')) !== null;
    }

    private function booleanSetting(string $key, bool $default): bool
    {
        $value = $this->nullableString($this->setting($key));

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function booleanSettingExists(string $key): bool
    {
        return $this->settingExists($key);
    }

    private function settingExists(string $key): bool
    {
        return array_key_exists($key, $this->settings());
    }

    private function setting(string $key): ?string
    {
        return $this->settings()[$key] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function settings(): array
    {
        if (is_array($this->loadedSettings)) {
            return $this->loadedSettings;
        }

        return $this->loadedSettings = $this->settingStore->plainValues();
    }

    private function storePlainSetting(string $key, ?string $value): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    private function storeEncryptedSetting(string $key, ?string $value): void
    {
        $payload = $value !== null && trim($value) !== ''
            ? Crypt::encryptString(trim($value))
            : null;

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $payload]
        );
    }

    private function resolveStoredSecret(string $key): ?string
    {
        $value = $this->nullableString($this->setting($key));

        if ($value === null) {
            return null;
        }

        try {
            return $this->nullableString(Crypt::decryptString($value));
        } catch (Throwable) {
            return null;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
