<?php

namespace App\Support;

use RuntimeException;

class OnvifXml
{
    public static function assertSafe(string $payload): void
    {
        // Reject DTDs before libxml processes the internal subset. NONET and
        // checking DOMDocument::doctype after parsing are too late for parser bugs.
        if (strlen($payload) > 1024 * 1024
            || str_contains($payload, "\0")
            || ! mb_check_encoding($payload, 'UTF-8')
            || preg_match('/<!DOCTYPE|<!ENTITY/i', $payload)) {
            throw new RuntimeException('The camera returned an unsafe or oversized ONVIF XML response.');
        }
    }
}
