<?php

namespace App\Livewire\Admin;

use App\Services\AuthenticationSettingsService;
use App\Services\GoogleOAuthTestService;
use Livewire\Component;

class AuthSettingsPanel extends Component
{
    public string $manualAuthEnabled = '0';

    public string $googleAuthEnabled = '0';

    public string $googleClientId = '';

    public string $googleClientSecret = '';

    public string $googleRedirectUri = '';

    public ?string $statusMessage = null;

    public string $statusType = 'neutral';

    public ?string $googleVerifiedFingerprint = null;

    public ?string $googleVerifiedAt = null;

    public ?string $googleVerifiedEmail = null;

    public bool $localAdminExists = false;

    public bool $googleAdminExists = false;

    public function mount(AuthenticationSettingsService $settings, GoogleOAuthTestService $tester): void
    {
        $this->loadFromSettings($settings);

        $draft = $tester->draft(request(), GoogleOAuthTestService::CONTEXT_ADMIN);

        if (is_array($draft)) {
            $this->googleClientId = $draft['client_id'];
            $this->googleClientSecret = $draft['client_secret'];
            $this->googleRedirectUri = $draft['redirect_uri'];
        }

        $verified = $tester->verified(request(), GoogleOAuthTestService::CONTEXT_ADMIN);

        if ($verified !== null) {
            $this->googleVerifiedFingerprint = $verified['fingerprint'];
            $this->googleVerifiedAt = $verified['tested_at'];
            $this->googleVerifiedEmail = $verified['tested_email'];
        }

        $result = $tester->consumeResult(request(), GoogleOAuthTestService::CONTEXT_ADMIN);

        if (is_array($result)) {
            $this->statusMessage = $result['message'] ?? null;
            $this->statusType = $result['status'] === 'success' ? 'success' : 'error';

            if (($result['status'] ?? null) === 'success') {
                $this->googleVerifiedFingerprint = $result['fingerprint'] ?? null;
                $this->googleVerifiedAt = $result['tested_at'] ?? null;
                $this->googleVerifiedEmail = $result['tested_email'] ?? null;
                $this->googleAuthEnabled = '1';
            }
        }
    }

    public function getGoogleVerificationIsCurrentProperty(): bool
    {
        $fingerprint = $this->currentGoogleFingerprint();
        $verified = app(GoogleOAuthTestService::class)->verified(request(), GoogleOAuthTestService::CONTEXT_ADMIN);

        if ($verified !== null && hash_equals($verified['fingerprint'], $fingerprint)) {
            return true;
        }

        $saved = app(AuthenticationSettingsService::class)->googleConfiguration();

        return $saved['verified']
            && $saved['fingerprint'] !== null
            && hash_equals($saved['fingerprint'], $fingerprint);
    }

    public function beginGoogleTest(GoogleOAuthTestService $tester)
    {
        $validated = $this->validate([
            'googleClientId' => ['required', 'string', 'max:255'],
            'googleClientSecret' => ['required', 'string', 'max:255'],
            'googleRedirectUri' => ['required', 'url', 'max:255'],
        ]);

        $tester->begin(request(), GoogleOAuthTestService::CONTEXT_ADMIN, [
            'client_id' => $validated['googleClientId'],
            'client_secret' => $validated['googleClientSecret'],
            'redirect_uri' => $validated['googleRedirectUri'],
        ]);

        return redirect()->route('auth.google.test.redirect');
    }

    public function save(AuthenticationSettingsService $settings): void
    {
        $this->resetErrorBag();
        $this->statusMessage = null;
        $this->statusType = 'neutral';

        $validated = $this->validate([
            'manualAuthEnabled' => ['required', 'in:0,1'],
            'googleAuthEnabled' => ['required', 'in:0,1'],
            'googleClientId' => ['nullable', 'string', 'max:255'],
            'googleClientSecret' => ['nullable', 'string', 'max:255'],
            'googleRedirectUri' => ['nullable', 'url', 'max:255'],
        ]);

        $manualEnabled = $validated['manualAuthEnabled'] === '1';
        $googleEnabled = $validated['googleAuthEnabled'] === '1';

        if (! $manualEnabled && ! $googleEnabled) {
            $this->addError('manualAuthEnabled', 'At least one authentication method must remain enabled.');
        }

        if ($googleEnabled && ! $this->googleVerificationIsCurrent) {
            $this->addError('googleClientId', 'Validate the Google OAuth credentials before enabling Google sign-in.');
        }

        if (! $settings->adminCanAuthenticateWith($manualEnabled, $googleEnabled)) {
            $this->addError('manualAuthEnabled', 'The selected authentication state would leave no admin able to sign in. Keep one admin available through local sign-in or a linked Google account.');
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        $verified = app(GoogleOAuthTestService::class)->verified(request(), GoogleOAuthTestService::CONTEXT_ADMIN);
        $verified = $verified !== null && hash_equals($verified['fingerprint'], $this->currentGoogleFingerprint())
            ? $verified
            : null;

        $settings->saveConfiguration(
            $manualEnabled,
            $googleEnabled,
            [
                'client_id' => $validated['googleClientId'],
                'client_secret' => $validated['googleClientSecret'],
                'redirect_uri' => $validated['googleRedirectUri'],
            ],
            $verified,
            markSetupComplete: true,
        );

        app(GoogleOAuthTestService::class)->forgetVerified(request(), GoogleOAuthTestService::CONTEXT_ADMIN);

        $this->loadFromSettings($settings);
        $this->statusMessage = 'Authentication settings updated.';
        $this->statusType = 'success';
    }

    public function render()
    {
        return view('livewire.admin.auth-settings-panel');
    }

    private function currentGoogleFingerprint(): string
    {
        return app(AuthenticationSettingsService::class)->googleConfigurationFingerprint(
            $this->googleClientId,
            $this->googleClientSecret,
            $this->googleRedirectUri,
        );
    }

    private function loadFromSettings(AuthenticationSettingsService $settings): void
    {
        $google = $settings->googleConfiguration();

        $this->manualAuthEnabled = $settings->manualAuthEnabled() ? '1' : '0';
        $this->googleAuthEnabled = $settings->googleAuthEnabled() ? '1' : '0';
        $this->googleClientId = $google['client_id'];
        $this->googleClientSecret = $google['client_secret'];
        $this->googleRedirectUri = $google['redirect_uri'];
        $this->googleVerifiedFingerprint = $google['verified'] ? $google['fingerprint'] : null;
        $this->googleVerifiedAt = $google['tested_at'];
        $this->googleVerifiedEmail = $google['tested_email'];
        $this->localAdminExists = $settings->localAdminExists();
        $this->googleAdminExists = $settings->googleAdminExists();
    }
}
