<?php

namespace App\Livewire\Auth;

use App\Services\AuthenticationSettingsService;
use App\Services\LocalAuthenticationService;
use Livewire\Component;

class UnifiedLoginScreen extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    public bool $manualAuthAvailable = false;

    public bool $googleAuthAvailable = false;

    public function mount(AuthenticationSettingsService $settings): void
    {
        $this->manualAuthAvailable = $settings->manualAuthEnabled();
        $this->googleAuthAvailable = $settings->googleAuthEnabled();
    }

    public function login(LocalAuthenticationService $localAuthentication, AuthenticationSettingsService $settings)
    {
        if (!$settings->manualAuthEnabled()) {
            $this->addError('email', 'Local sign-in is disabled for this application.');

            return null;
        }

        $validated = $this->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = $localAuthentication->attempt($validated['email'], $validated['password'], $this->remember);

        if ($user === null) {
            $this->addError('email', 'The provided email or password was not accepted.');

            return null;
        }

        return redirect()->intended(route('camera-fleet.index'));
    }

    public function render()
    {
        return view('livewire.auth.unified-login-screen');
    }
}