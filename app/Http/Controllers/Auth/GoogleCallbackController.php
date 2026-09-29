<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AllowedLoginEmail;
use App\Models\User;
use App\Services\AuthenticationSettingsService;
use App\Services\GoogleOAuthTestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleCallbackController extends Controller
{
    public function __invoke(
        Request $request,
        AuthenticationSettingsService $settings,
        GoogleOAuthTestService $tester,
    ): RedirectResponse {
        $pendingTest = $tester->applyPendingConfiguration($request);

        if ($pendingTest !== null) {
            try {
                $googleUser = Socialite::driver('google')->user();
            } catch (Throwable) {
                $tester->storeFailure(
                    $request,
                    $pendingTest['context'] ?? null,
                    'Google did not complete the validation flow. Confirm the client ID, client secret, redirect URI, and authorized redirect settings, then try again.',
                );

                return redirect()->to($tester->returnUrl($pendingTest['context'] ?? GoogleOAuthTestService::CONTEXT_SETUP));
            }

            $testedEmail = Str::lower(trim((string) $googleUser->getEmail()));

            if ($testedEmail === '') {
                $tester->storeFailure($request, $pendingTest['context'] ?? null, 'Google did not return an email address for the test account.');

                return redirect()->to($tester->returnUrl($pendingTest['context'] ?? GoogleOAuthTestService::CONTEXT_SETUP));
            }

            $tester->storeSuccess(
                $request,
                $pendingTest,
                $testedEmail,
                trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: $googleUser->getEmail())),
            );

            return redirect()->to($tester->returnUrl($pendingTest['context']));
        }

        if (! $settings->googleAuthEnabled()) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Google sign-in is not enabled for this application.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Google sign-in could not be completed. Try again after checking the OAuth redirect settings.');
        }

        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $isBootstrapSignIn = ! User::query()->where('is_admin', true)->exists();

        if ($email === '') {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Google did not return an email address for this account.');
        }

        $verifiedSetupEmail = Str::lower(trim((string) ($settings->googleConfiguration()['tested_email'] ?? '')));

        if ($isBootstrapSignIn && $verifiedSetupEmail !== '' && $email !== $verifiedSetupEmail) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Sign in first with the Google account used to validate this application during setup.');
        }

        if (! $isBootstrapSignIn && ! AllowedLoginEmail::isAllowed($email)) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'This Google email address is not approved for operator access yet. Ask an admin to add it under Admin > Operator access.');
        }

        $user = User::query()
            ->when($googleUser->getId(), fn ($query, string $googleId) => $query->where('google_id', $googleId))
            ->orWhere('email', $email)
            ->first() ?? new User;

        $attributes = [
            'name' => trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: $email)),
            'email' => $email,
            'email_verified_at' => now(),
            'google_id' => (string) $googleUser->getId(),
            'avatar_url' => $googleUser->getAvatar(),
            'local_auth_enabled' => $user->exists ? $user->hasLocalAuth() : false,
        ];

        if (! $user->exists) {
            $attributes['password'] = Str::random(40);
            $attributes['password_updated_at'] = null;
        }

        $user->forceFill($attributes)->save();

        if (! User::query()->where('is_admin', true)->exists()) {
            $user->forceFill(['is_admin' => true])->save();
        }

        AllowedLoginEmail::query()->firstOrCreate(
            ['email' => $email],
            ['added_by_user_id' => $isBootstrapSignIn ? $user->id : auth()->id()]
        );

        auth()->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('camera-fleet.index'));
    }
}
