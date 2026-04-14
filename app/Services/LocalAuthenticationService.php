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

        if (!$user instanceof User || !$user->hasLocalAuth() || !Hash::check($password, $user->password)) {
            return null;
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill([
                'password' => $password,
                'password_updated_at' => now(),
            ])->save();
        }

        Auth::login($user, $remember);
        request()->session()->regenerate();

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
            : (User::query()->where('email', $normalizedEmail)->first() ?? new User());

        $user->forceFill([
            'name' => trim($name),
            'email' => $normalizedEmail,
            'password' => $password,
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