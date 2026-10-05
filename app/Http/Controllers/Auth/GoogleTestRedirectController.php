<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GoogleOAuthTestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class GoogleTestRedirectController extends Controller
{
    public function __invoke(Request $request, GoogleOAuthTestService $tester): RedirectResponse|SymfonyRedirectResponse
    {
        $pending = $tester->applyPendingConfiguration($request);

        if ($pending === null) {
            return redirect()
                ->route('setup.index')
                ->with('setup_error', 'No Google OAuth test is pending. Start the validation flow from the setup or admin auth form.');
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }
}
