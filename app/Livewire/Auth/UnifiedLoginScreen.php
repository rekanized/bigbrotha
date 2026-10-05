<?php

namespace App\Livewire\Auth;

use App\Services\AuthenticationSettingsService;
use App\Services\LocalAuthenticationService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Component;

class UnifiedLoginScreen extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $showLocalForm = false;

    public bool $remember = false;

    public bool $manualAuthAvailable = false;

    public bool $googleAuthAvailable = false;

    public function mount(AuthenticationSettingsService $settings): void
    {
        $this->manualAuthAvailable = $settings->manualAuthEnabled();
        $this->googleAuthAvailable = $settings->googleAuthEnabled();
        $this->showLocalForm = $this->manualAuthAvailable && ! $this->googleAuthAvailable;
    }

    public function beginLocalSignIn(): void
    {
        $this->showLocalForm = true;
    }

    public function login(LocalAuthenticationService $localAuthentication, AuthenticationSettingsService $settings)
    {
        $this->showLocalForm = true;

        if (! $settings->manualAuthEnabled()) {
            $this->addError('email', 'Local sign-in is disabled for this application.');

            return null;
        }

        $validated = $this->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        abort_if(auth()->check(), 403);
        $accountKey = 'login:account:'.hash('sha256', $localAuthentication->normalizeEmail($validated['email']));
        $ipKey = 'login:ip:'.hash('sha256', (string) request()->ip());

        if (RateLimiter::tooManyAttempts($accountKey, 5) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            $this->password = '';
            $this->addError('email', 'Too many sign-in attempts. Please wait a minute and try again.');

            return null;
        }

        RateLimiter::hit($accountKey, 60);
        RateLimiter::hit($ipKey, 60);

        $user = $localAuthentication->attempt($validated['email'], $validated['password'], $this->remember);

        $this->password = '';

        if ($user === null) {
            $this->addError('email', 'The provided email or password was not accepted.');

            return null;
        }

        RateLimiter::clear($accountKey);

        return redirect()->intended(route('live-wall.index'));
    }

    public function render()
    {
        return view('livewire.auth.unified-login-screen');
    }
}
