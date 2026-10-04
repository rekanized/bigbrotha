<?php

namespace App\Support\Logging;

use Throwable;

class SensitiveDataRedactor
{
    public static function message(string $value): string
    {
        $value = preg_replace('#\b([a-z][a-z0-9+.-]*://)[^\s/@]+@#i', '$1[redacted]@', $value) ?? $value;

        return preg_replace('/([?&](?:token|secret|password|client_secret|access_token|refresh_token|code)=)[^&\s#\'"<>]+/i', '$1[redacted]', $value) ?? $value;
    }

    public static function context(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($key) && preg_match('/(?:^|_)(?:password|pass|secret|token|authorization|cookie)(?:$|_)/i', $key)) {
                $context[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $context[$key] = self::context($value);
            } elseif ($value instanceof Throwable) {
                $context[$key] = self::message((string) $value);
            } elseif (is_string($value)) {
                $context[$key] = self::message($value);
            }
        }

        return $context;
    }
}
