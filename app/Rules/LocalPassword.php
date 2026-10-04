<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class LocalPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) < 12 || strlen($value) > 72 || str_contains($value, "\0")) {
            $fail('The :attribute must contain at least 12 characters, at most 72 bytes, and no null characters.');
        }
    }
}
