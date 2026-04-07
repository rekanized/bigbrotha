<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AllowedLoginEmail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Google sign-in could not be completed. Try again after checking the OAuth redirect settings.');
        }

        $email = Str::lower(trim((string) $googleUser->getEmail()));
        $isBootstrapSignIn = !User::query()->exists();

        if ($email === '') {
            return redirect()
                ->route('login')
                ->with('auth_error', 'Google did not return an email address for this account.');
        }

        if (!$isBootstrapSignIn && !AllowedLoginEmail::isAllowed($email)) {
            return redirect()
                ->route('login')
                ->with('auth_error', 'This Google email address is not approved for operator access yet. Ask an admin to add it under Admin > Operator access.');
        }

        $user = User::query()
            ->when($googleUser->getId(), fn ($query, string $googleId) => $query->where('google_id', $googleId))
            ->orWhere('email', $email)
            ->first() ?? new User();

        $attributes = [
            'name' => trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: $email)),
            'email' => $email,
            'email_verified_at' => now(),
            'google_id' => (string) $googleUser->getId(),
            'avatar_url' => $googleUser->getAvatar(),
        ];

        if (!$user->exists) {
            $attributes['password'] = Str::random(40);
        }

        $user->forceFill($attributes)->save();

        if (!User::query()->where('is_admin', true)->exists()) {
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