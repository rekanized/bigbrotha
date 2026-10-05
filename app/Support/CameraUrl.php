<?php

namespace App\Support;

class CameraUrl
{
    public static function withoutCredentials(string $url): string
    {
        return preg_replace('#\A([a-z][a-z0-9+.-]*://)[^/\s?\#]*@#i', '$1', $url) ?? $url;
    }
}
