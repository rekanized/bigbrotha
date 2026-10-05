<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AllowedLoginEmail;
use App\Models\User;
use App\Services\AuthenticationSettingsService;
use App\Services\GoogleIdentityService;
use App\Services\GoogleOAuthTestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

            if (! app(GoogleIdentityService::class)->isVerified($googleUser)) {
                $tester->storeFailure($request, $pendingTest['context'] ?? null, 'Google did not return an email address for the test account.');

                return redirect()->to($tester->returnUrl($pendingTest['context'] ?? GoogleOAuthTestService::CONTEXT_SETUP));
            }

            $tester->storeSuccess(
                $request,
                $pendingTest,
                $testedEmail,
                trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: $googleUser->getEmail())),
                (string) $googleUser->getId(),
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

        if (! app(GoogleIdentityService::class)->isVerified($googleUser)) {
            return redirect()->route('login')->with('auth_error', 'Google must return a verified email address and account identity.');
        }

        return Cache::lock('authentication:google-login', 30)->block(5, function () use ($googleUser, $settings, $request): RedirectResponse {
            return $this->signIn($googleUser, $settings, $request);
        });
    }

    private function signIn(\Laravel\Socialite\Two\User $googleUser, AuthenticationSettingsService $settings, Request $request): RedirectResponse
    {
        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $googleId = (string) $googleUser->getId();
        $configuration = $settings->googleConfiguration();
        $isBootstrapSignIn = ! User::query()->exists()
            && ($configuration['tested_email'] ?? null) === $email
            && ($configuration['tested_google_id'] ?? null) === $googleId;

        if (! $isBootstrapSignIn && ! AllowedLoginEmail::isAllowed($email)) {
            return redirect()->route('login')->with('auth_error', 'This Google account is not approved for operator access.');
        }

        $byId = User::query()->where('google_id', $googleId)->first();
        $byEmail = User::query()->where('email', $email)->first();

        if (($byId && $byEmail && ! $byId->is($byEmail))
            || ($byEmail && filled($byEmail->google_id) && $byEmail->google_id !== $googleId)
            || (! $byId && ! $isBootstrapSignIn && ! app(GoogleIdentityService::class)->isAuthoritative($googleUser))) {
            return redirect()->route('login')->with('auth_error', 'This Google identity cannot be linked automatically. Ask an administrator to review the account.');
        }

        $user = $byId ?? $byEmail ?? new User;
        $attributes = [
            'name' => trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: $email)),
            'email' => $email,
            'email_verified_at' => now(),
            'google_id' => $googleId,
            'avatar_url' => $googleUser->getAvatar(),
            'local_auth_enabled' => $user->exists ? $user->hasLocalAuth() : false,
        ];

        if (! $user->exists) {
            $attributes['password'] = Str::random(40);
            $attributes['password_updated_at'] = null;
            $attributes['is_admin'] = $isBootstrapSignIn;
        }

        $user->forceFill($attributes)->save();

        if ($isBootstrapSignIn) {
            AllowedLoginEmail::query()->firstOrCreate(['email' => $email], ['added_by_user_id' => $user->id]);
        }

        auth()->login($user, remember: false);
        $request->session()->regenerate();
        $request->session()->put('auth_method', 'google');
        $request->session()->put('password_hash_web', $user->getAuthPassword());

        return redirect()->intended(route('live-wall.index'));
    }
}
