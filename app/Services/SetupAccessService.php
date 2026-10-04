<?php

namespace App\Services;

class SetupAccessService
{
    public function token(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new \RuntimeException('Setup requires an application encryption key.');
        }

        return hash_hmac('sha256', 'bigbrotha:initial-setup', $key);
    }

    public function accepts(string $token): bool
    {
        return hash_equals($this->token(), trim($token));
    }
}
