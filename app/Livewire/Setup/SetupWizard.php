<?php

namespace App\Livewire\Setup;

use App\Models\AllowedLoginEmail;
use App\Services\AuthenticationSettingsService;
use App\Services\GoogleOAuthTestService;
use App\Services\LocalAuthenticationService;
use Livewire\Component;

class SetupWizard extends Component
{
    private const SESSION_DRAFT_KEY = 'setup.wizard.draft';

    public string $manualAuthEnabled = '1';

    public string $googleAuthEnabled = '0';

    public string $adminName = '';

    public string $adminEmail = '';

    public string $adminPassword = '';

    public string $adminPasswordConfirmation = '';

    public string $googleClientId = '';

    public string $googleClientSecret = '';

    public string $googleRedirectUri = '';

    public ?string $statusMessage = null;

    public string $statusType = 'neutral';

    public ?string $googleVerifiedFingerprint = null;

    public ?string $googleVerifiedAt = null;

    public ?string $googleVerifiedEmail = null;

    public function mount(AuthenticationSettingsService $settings, GoogleOAuthTestService $tester): void
    {
        if ($settings->isSetupComplete()) {
            $this->redirectRoute(auth()->check() ? 'camera-fleet.index' : 'login', navigate: true);

            return;
        }

        $this->googleRedirectUri = route('auth.google.callback');

        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $this->fillFromDraft($request->session()->get(self::SESSION_DRAFT_KEY));

        $draft = $tester->draft($request, GoogleOAuthTestService::CONTEXT_SETUP);

        if (is_array($draft)) {
            $this->googleClientId = $draft['client_id'];
            $this->googleClientSecret = $draft['client_secret'];
            $this->googleRedirectUri = $draft['redirect_uri'];
        }

        $verified = $tester->verified($request, GoogleOAuthTestService::CONTEXT_SETUP);

        if ($verified !== null) {
            $this->googleVerifiedFingerprint = $verified['fingerprint'];
            $this->googleVerifiedAt = $verified['tested_at'];
            $this->googleVerifiedEmail = $verified['tested_email'];
        }

        $result = $tester->consumeResult($request, GoogleOAuthTestService::CONTEXT_SETUP);

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
        $verified = app(GoogleOAuthTestService::class)->verified(request(), GoogleOAuthTestService::CONTEXT_SETUP);

        return $verified !== null
            && hash_equals($verified['fingerprint'], $this->currentGoogleFingerprint());
    }

    public function beginGoogleTest(GoogleOAuthTestService $tester)
    {
        $validated = $this->validate([
            'googleClientId' => ['required', 'string', 'max:255'],
            'googleClientSecret' => ['required', 'string', 'max:255'],
            'googleRedirectUri' => ['required', 'url', 'max:255'],
        ]);

        $this->rememberDraft();

        $tester->begin(request(), GoogleOAuthTestService::CONTEXT_SETUP, [
            'client_id' => $validated['googleClientId'],
            'client_secret' => $validated['googleClientSecret'],
            'redirect_uri' => $validated['googleRedirectUri'],
        ]);

        return redirect()->route('auth.google.test.redirect');
    }

    public function save(AuthenticationSettingsService $settings, LocalAuthenticationService $localAuthentication)
    {
        $this->resetErrorBag();
        $this->statusMessage = null;
        $this->statusType = 'neutral';

        $this->rememberDraft();

        $validated = $this->validate([
            'manualAuthEnabled' => ['required', 'in:0,1'],
            'googleAuthEnabled' => ['required', 'in:0,1'],
            'adminName' => ['nullable', 'string', 'max:255'],
            'adminEmail' => ['nullable', 'email:rfc', 'max:255'],
            'adminPassword' => ['nullable', 'string', 'min:10'],
            'adminPasswordConfirmation' => ['nullable', 'string', 'min:10'],
            'googleClientId' => ['nullable', 'string', 'max:255'],
            'googleClientSecret' => ['nullable', 'string', 'max:255'],
            'googleRedirectUri' => ['nullable', 'url', 'max:255'],
        ]);

        $manualEnabled = $validated['manualAuthEnabled'] === '1';
        $googleEnabled = $validated['googleAuthEnabled'] === '1';

        if (! $manualEnabled && ! $googleEnabled) {
            $this->addError('manualAuthEnabled', 'Enable at least one authentication method before finishing setup.');
        }

        if ($manualEnabled) {
            if (trim((string) $validated['adminName']) === '') {
                $this->addError('adminName', 'Enter the initial administrator name when local sign-in is enabled.');
            }

            if (trim((string) $validated['adminEmail']) === '') {
                $this->addError('adminEmail', 'Enter the initial administrator email when local sign-in is enabled.');
            }

            if (trim((string) $validated['adminPassword']) === '') {
                $this->addError('adminPassword', 'Enter the initial administrator password when local sign-in is enabled.');
            }

            if (($validated['adminPassword'] ?? '') !== ($validated['adminPasswordConfirmation'] ?? '')) {
                $this->addError('adminPasswordConfirmation', 'The administrator password confirmation must match.');
            }
        }

        if ($googleEnabled) {
            if (trim((string) $validated['googleClientId']) === '') {
                $this->addError('googleClientId', 'Enter the Google OAuth client ID before enabling Google sign-in.');
            }

            if (trim((string) $validated['googleClientSecret']) === '') {
                $this->addError('googleClientSecret', 'Enter the Google OAuth client secret before enabling Google sign-in.');
            }

            if (trim((string) $validated['googleRedirectUri']) === '') {
                $this->addError('googleRedirectUri', 'Enter the Google OAuth redirect URI before enabling Google sign-in.');
            }

            if (! $this->googleVerificationIsCurrent) {
                $this->addError('googleClientId', 'Run a successful Google OAuth validation before enabling Google sign-in.');
            }
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return null;
        }

        $user = null;

        if ($manualEnabled) {
            $user = $localAuthentication->createOrUpdateLocalUser(
                $validated['adminName'],
                $validated['adminEmail'],
                $validated['adminPassword'],
                true,
            );

            if ($googleEnabled) {
                AllowedLoginEmail::query()->firstOrCreate(
                    ['email' => AllowedLoginEmail::normalizeEmail($validated['adminEmail'])],
                    ['added_by_user_id' => $user->id],
                );
            }
        }

        $verified = $googleEnabled
            ? app(GoogleOAuthTestService::class)->verified(request(), GoogleOAuthTestService::CONTEXT_SETUP)
            : null;

        $settings->saveConfiguration(
            $manualEnabled,
            $googleEnabled,
            [
                'client_id' => $validated['googleClientId'],
                'client_secret' => $validated['googleClientSecret'],
                'redirect_uri' => $validated['googleRedirectUri'],
            ],
            $googleEnabled && $verified !== null ? [
                'fingerprint' => $verified['fingerprint'],
                'tested_at' => $verified['tested_at'],
                'tested_email' => $verified['tested_email'],
            ] : null,
            markSetupComplete: true,
        );

        $request = request();

        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_DRAFT_KEY);
        }

        app(GoogleOAuthTestService::class)->forgetVerified($request, GoogleOAuthTestService::CONTEXT_SETUP);

        if ($user !== null) {
            auth()->login($user, true);

            if ($request->hasSession()) {
                $request->session()->regenerate();
                $request->session()->flash('status', 'Setup complete. The initial administrator account is signed in.');
            }

            $this->redirectRoute('camera-fleet.index', navigate: true);

            return null;
        }

        if ($request->hasSession()) {
            $request->session()->flash('status', 'Setup complete. Sign in with the enabled authentication method.');
        }

        $this->redirectRoute('login', navigate: true);

        return null;
    }

    public function render()
    {
        return view('livewire.setup.setup-wizard');
    }

    private function currentGoogleFingerprint(): string
    {
        return app(AuthenticationSettingsService::class)->googleConfigurationFingerprint(
            $this->googleClientId,
            $this->googleClientSecret,
            $this->googleRedirectUri,
        );
    }

    private function rememberDraft(): void
    {
        if (! request()->hasSession()) {
            return;
        }

        request()->session()->put(self::SESSION_DRAFT_KEY, [
            'manualAuthEnabled' => $this->manualAuthEnabled,
            'googleAuthEnabled' => $this->googleAuthEnabled,
            'adminName' => $this->adminName,
            'adminEmail' => $this->adminEmail,
            'googleClientId' => $this->googleClientId,
            'googleClientSecret' => $this->googleClientSecret,
            'googleRedirectUri' => $this->googleRedirectUri,
        ]);
    }

    private function fillFromDraft(mixed $draft): void
    {
        if (! is_array($draft)) {
            return;
        }

        $this->manualAuthEnabled = in_array($draft['manualAuthEnabled'] ?? null, ['0', '1'], true)
            ? $draft['manualAuthEnabled']
            : $this->manualAuthEnabled;

        $this->googleAuthEnabled = in_array($draft['googleAuthEnabled'] ?? null, ['0', '1'], true)
            ? $draft['googleAuthEnabled']
            : $this->googleAuthEnabled;

        $this->adminName = (string) ($draft['adminName'] ?? $this->adminName);
        $this->adminEmail = (string) ($draft['adminEmail'] ?? $this->adminEmail);
        $this->googleClientId = (string) ($draft['googleClientId'] ?? $this->googleClientId);
        $this->googleClientSecret = (string) ($draft['googleClientSecret'] ?? $this->googleClientSecret);
        $this->googleRedirectUri = (string) ($draft['googleRedirectUri'] ?? $this->googleRedirectUri);
    }
}
