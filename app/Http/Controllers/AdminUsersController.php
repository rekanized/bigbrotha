<?php

namespace App\Http\Controllers;

use App\Models\AllowedLoginEmail;
use App\Models\User;
use App\Rules\LocalPassword;
use App\Services\AuthenticationSettingsService;
use App\Services\LocalAuthenticationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUsersController extends Controller
{
    public function index(): View
    {
        return view('admin.users', [
            'users' => User::query()
                ->orderByDesc('is_admin')
                ->orderBy('name')
                ->orderBy('email')
                ->get(),
            'allowedLoginEmails' => AllowedLoginEmail::query()
                ->with('addedBy')
                ->orderBy('email')
                ->get(),
        ]);
    }

    public function storeAllowedEmail(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('allowedEmail', [
            'email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $email = AllowedLoginEmail::normalizeEmail($validated['email']);

        if (AllowedLoginEmail::isAllowed($email)) {
            return redirect()
                ->route('admin.users.index')
                ->with('status', 'Sign-in access is already allowed for '.$email.'.');
        }

        AllowedLoginEmail::query()->create([
            'email' => $email,
            'added_by_user_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Allowed Google sign-in for '.$email.'.');
    }

    public function storeLocalUser(Request $request, LocalAuthenticationService $localAuthentication): RedirectResponse
    {
        $validated = $request->validateWithBag('localUser', [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', new LocalPassword, 'confirmed'],
            'is_admin' => ['nullable', 'boolean'],
        ]);

        $email = $localAuthentication->normalizeEmail($validated['email']);
        $existingUser = User::query()->where('email', $email)->first();
        $wasExisting = $existingUser instanceof User;

        $user = $localAuthentication->createOrUpdateLocalUser(
            $validated['name'],
            $email,
            $validated['password'],
            (bool) ($validated['is_admin'] ?? false),
            $existingUser,
        );

        return redirect()
            ->route('admin.users.index')
            ->with('status', $wasExisting
                ? 'Enabled local password sign-in for '.$user->email.'.'
                : 'Created a local operator account for '.$user->email.'.');
    }

    public function updateLocalPassword(Request $request, User $user, LocalAuthenticationService $localAuthentication): RedirectResponse
    {
        $validated = $request->validateWithBag('localPassword', [
            'password' => ['required', 'string', new LocalPassword, 'confirmed'],
        ]);

        $localAuthentication->updateLocalPassword($user, $validated['password']);

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Updated the local password for '.$user->email.'.');
    }

    public function updateAdminRole(Request $request, User $user): RedirectResponse
    {
        return DB::transaction(function () use ($request, $user): RedirectResponse {
            User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->get();
            abort_unless($request->user()?->fresh()?->isAdmin(), 403);

            return $this->changeAdminRole($request, $user->refresh());
        });
    }

    private function changeAdminRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        $shouldBeAdmin = (bool) $validated['is_admin'];

        if (! $shouldBeAdmin && $user->isAdmin() && User::query()->where('is_admin', true)->count() <= 1) {
            return redirect()
                ->route('admin.users.index')
                ->with('status_error', 'At least one admin must remain assigned. Promote another operator before removing the last admin.');
        }

        if ($user->isAdmin() === $shouldBeAdmin) {
            return redirect()
                ->route('admin.users.index')
                ->with('status', 'No admin role change was needed for '.$user->name.'.');
        }

        $settings = app(AuthenticationSettingsService::class);
        $settings->refresh();
        if (! $shouldBeAdmin && ! $settings->adminCanAuthenticateWith($settings->manualAuthEnabled(), $settings->googleAuthEnabled(), exceptUserId: $user->id)) {
            return redirect()->route('admin.users.index')->with('status_error', 'At least one administrator must remain able to sign in through an enabled method.');
        }

        $user->forceFill([
            'is_admin' => $shouldBeAdmin,
        ])->save();

        return redirect()
            ->route('admin.users.index')
            ->with('status', ($shouldBeAdmin ? 'Promoted ' : 'Removed admin access for ').$user->name.'.');
    }

    public function destroyAllowedEmail(AllowedLoginEmail $allowedLoginEmail): RedirectResponse
    {
        return DB::transaction(function () use ($allowedLoginEmail): RedirectResponse {
            User::query()->where('is_admin', true)->orderBy('id')->lockForUpdate()->get();
            abort_unless(auth()->user()?->fresh()?->isAdmin(), 403);

            return $this->removeAllowedEmail($allowedLoginEmail);
        });
    }

    private function removeAllowedEmail(AllowedLoginEmail $allowedLoginEmail): RedirectResponse
    {
        $linkedUser = User::query()
            ->where('email', $allowedLoginEmail->email)
            ->first();

        if ($linkedUser?->isAdmin() && User::query()->where('is_admin', true)->count() <= 1) {
            return redirect()
                ->route('admin.users.index')
                ->with('status_error', 'At least one admin sign-in email must remain allowed. Add another admin email before removing the last admin login.');
        }

        $settings = app(AuthenticationSettingsService::class);
        $settings->refresh();
        if (! $settings->adminCanAuthenticateWith($settings->manualAuthEnabled(), $settings->googleAuthEnabled(), exceptGoogleEmail: $allowedLoginEmail->email)) {
            return redirect()->route('admin.users.index')->with('status_error', 'At least one administrator must remain able to sign in through an enabled method.');
        }

        $email = $allowedLoginEmail->email;
        $allowedLoginEmail->delete();

        return redirect()
            ->route('admin.users.index')
            ->with('status', 'Removed Google sign-in access for '.$email.'.');
    }
}
