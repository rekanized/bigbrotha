<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LocalAuthenticationService
{
    public function attempt(string $email, string $password, bool $remember = false): ?User
    {
        $user = User::query()
            ->where('email', $this->normalizeEmail($email))
            ->first();

        // Always verify a hash so unknown accounts do not have a cheap timing path.
        $hash = $user?->password ?? '$2y$12$MN0VQ0.UD1F6JGH09j2P2uKingVWQCQcNZQ5j8o3CPltxARYk8nQG';
        $validPassword = Hash::check($password, $hash);

        if (! $user instanceof User || ! $user->hasLocalAuth() || ! $validPassword) {
            return null;
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill([
                'password' => $password,
                'password_updated_at' => now(),
            ])->save();
        }

        Auth::login($user, $remember);

        if (request()->hasSession()) {
            request()->session()->regenerate();
            request()->session()->put('auth_method', 'local');
            request()->session()->put('password_hash_web', $user->getAuthPassword());
        }

        return $user;
    }

    public function createOrUpdateLocalUser(
        string $name,
        string $email,
        string $password,
        bool $isAdmin = false,
        ?User $user = null,
    ): User {
        $normalizedEmail = $this->normalizeEmail($email);
        $user = $user instanceof User
            ? $user
            : (User::query()->where('email', $normalizedEmail)->first() ?? new User);

        $user->forceFill([
            'name' => trim($name),
            'email' => $normalizedEmail,
            'password' => $password,
            'remember_token' => Str::random(60),
            'local_auth_enabled' => true,
            'password_updated_at' => now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'is_admin' => $isAdmin || $user->isAdmin(),
        ])->save();

        return $user;
    }

    public function updateLocalPassword(User $user, string $password): User
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
            'local_auth_enabled' => true,
            'password_updated_at' => now(),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
