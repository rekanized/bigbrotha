<?php

namespace App\Services;

use Illuminate\Http\Request;

class GoogleOAuthTestService
{
    public const CONTEXT_SETUP = 'setup';

    public const CONTEXT_ADMIN = 'admin';

    private const SESSION_FORM_KEY = 'auth.google_test.forms';

    private const SESSION_PENDING_KEY = 'auth.google_test.pending';

    private const SESSION_RESULT_KEY = 'auth.google_test.results';

    private const SESSION_VERIFIED_KEY = 'auth.google_test.verified';

    public function __construct(private readonly AuthenticationSettingsService $settings) {}

    /**
     * @param  array{client_id: string, client_secret: string, redirect_uri: string}  $configuration
     */
    public function rememberDraft(Request $request, string $context, array $configuration): void
    {
        $request->session()->put(self::SESSION_FORM_KEY.'.'.$context, [
            'client_id' => trim($configuration['client_id']),
            'client_secret' => trim($configuration['client_secret']),
            'redirect_uri' => trim($configuration['redirect_uri']),
        ]);
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}|null
     */
    public function draft(Request $request, string $context): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $draft = $request->session()->get(self::SESSION_FORM_KEY.'.'.$context);

        return is_array($draft) ? $draft : null;
    }

    /**
     * @param  array{client_id: string, client_secret: string, redirect_uri: string}  $configuration
     */
    public function begin(Request $request, string $context, array $configuration): void
    {
        $this->rememberDraft($request, $context, $configuration);

        $request->session()->put(self::SESSION_PENDING_KEY, [
            'context' => $context,
            'configuration' => [
                'client_id' => trim($configuration['client_id']),
                'client_secret' => trim($configuration['client_secret']),
                'redirect_uri' => trim($configuration['redirect_uri']),
            ],
            'fingerprint' => $this->settings->googleConfigurationFingerprint(
                $configuration['client_id'],
                $configuration['client_secret'],
                $configuration['redirect_uri'],
            ),
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @return array{context: string, configuration: array{client_id: string, client_secret: string, redirect_uri: string}, fingerprint: string, started_at: string}|null
     */
    public function pending(Request $request): ?array
    {
        $pending = $request->session()->get(self::SESSION_PENDING_KEY);

        return is_array($pending) ? $pending : null;
    }

    /**
     * @return array{context: string, configuration: array{client_id: string, client_secret: string, redirect_uri: string}, fingerprint: string, started_at: string}|null
     */
    public function applyPendingConfiguration(Request $request): ?array
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return null;
        }

        config()->set('services.google.client_id', $pending['configuration']['client_id']);
        config()->set('services.google.client_secret', $pending['configuration']['client_secret']);
        config()->set('services.google.redirect', $pending['configuration']['redirect_uri']);

        return $pending;
    }

    /**
     * @return array{status: string, message: string, fingerprint?: string, tested_email?: string, tested_name?: string, tested_at?: string}|null
     */
    public function consumeResult(Request $request, string $context): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $key = self::SESSION_RESULT_KEY.'.'.$context;
        $result = $request->session()->get($key);
        $request->session()->forget($key);

        return is_array($result) ? $result : null;
    }

    /**
     * @return array{fingerprint: string, tested_email: string, tested_at: string}|null
     */
    public function verified(Request $request, string $context): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $verified = $request->session()->get(self::SESSION_VERIFIED_KEY.'.'.$context);

        return is_array($verified) ? $verified : null;
    }

    public function forgetVerified(Request $request, string $context): void
    {
        if ($request->hasSession()) {
            $request->session()->forget(self::SESSION_VERIFIED_KEY.'.'.$context);
        }
    }

    public function storeSuccess(Request $request, array $pending, string $email, string $name): void
    {
        $request->session()->put(self::SESSION_VERIFIED_KEY.'.'.$pending['context'], [
            'fingerprint' => $pending['fingerprint'],
            'tested_email' => $email,
            'tested_at' => now()->toIso8601String(),
        ]);

        $request->session()->put(self::SESSION_RESULT_KEY.'.'.$pending['context'], [
            'status' => 'success',
            'message' => 'Google OAuth credentials were verified successfully.',
            'fingerprint' => $pending['fingerprint'],
            'tested_email' => $email,
            'tested_name' => $name,
            'tested_at' => now()->toIso8601String(),
        ]);

        $request->session()->forget(self::SESSION_PENDING_KEY);
    }

    public function storeFailure(Request $request, ?string $context, string $message): void
    {
        $resolvedContext = in_array($context, [self::CONTEXT_SETUP, self::CONTEXT_ADMIN], true)
            ? $context
            : self::CONTEXT_SETUP;

        $request->session()->put(self::SESSION_RESULT_KEY.'.'.$resolvedContext, [
            'status' => 'error',
            'message' => $message,
        ]);

        $request->session()->forget(self::SESSION_PENDING_KEY);
    }

    public function returnUrl(string $context): string
    {
        return $context === self::CONTEXT_ADMIN
            ? route('admin.settings.index')
            : route('setup.index');
    }
}
