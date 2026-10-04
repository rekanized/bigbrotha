<?php

namespace App\Services;

use Laravel\Socialite\Two\User;

class GoogleIdentityService
{
    public function isVerified(User $user): bool
    {
        return trim((string) $user->getId()) !== ''
            && filter_var($user->getEmail(), FILTER_VALIDATE_EMAIL) !== false
            && (($user->user['email_verified'] ?? null) === true || ($user->user['verified_email'] ?? null) === true);
    }

    public function isAuthoritative(User $user): bool
    {
        $email = strtolower((string) $user->getEmail());
        $domain = substr($email, strrpos($email, '@') + 1);
        $hostedDomain = strtolower(trim((string) ($user->user['hd'] ?? '')));

        return $this->isVerified($user) && ($domain === 'gmail.com' || ($hostedDomain !== '' && $hostedDomain === $domain));
    }
}
