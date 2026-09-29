<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\GoogleOAuthTestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleTestCallbackController extends Controller
{
    public function __invoke(Request $request, GoogleOAuthTestService $tester): RedirectResponse
    {
        $pending = $tester->applyPendingConfiguration($request);

        if ($pending === null) {
            return redirect()
                ->route('setup.index')
                ->with('setup_error', 'The Google OAuth test session expired. Start the validation flow again.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            $tester->storeFailure(
                $request,
                $pending['context'] ?? null,
                'Google did not complete the validation flow. Confirm the client ID, client secret, redirect URI, and authorized redirect settings, then try again.',
            );

            return redirect()->to($tester->returnUrl($pending['context'] ?? GoogleOAuthTestService::CONTEXT_SETUP));
        }

        $testedEmail = Str::lower(trim((string) $googleUser->getEmail()));

        if ($testedEmail === '') {
            $tester->storeFailure($request, $pending['context'] ?? null, 'Google did not return an email address for the test account.');

            return redirect()->to($tester->returnUrl($pending['context'] ?? GoogleOAuthTestService::CONTEXT_SETUP));
        }

        $tester->storeSuccess(
            $request,
            $pending,
            $testedEmail,
            trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: $googleUser->getEmail())),
        );

        return redirect()->to($tester->returnUrl($pending['context']));
    }
}
