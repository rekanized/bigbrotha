<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthenticationSettingsService;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;

class GoogleRedirectController extends Controller
{
    public function __invoke(AuthenticationSettingsService $settings): RedirectResponse
    {
        abort_unless($settings->googleAuthEnabled(), 403, 'Google sign-in is not enabled for this application.');

        $google = $settings->googleConfiguration();

        abort_unless(
            $google['configured'] && $google['verified'],
            500,
            'Google OAuth is not configured.',
        );

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }
}
