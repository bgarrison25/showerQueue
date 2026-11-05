<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class HouseholdCredential implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || strlen($value) > 128 || !(preg_match('/^\d{6,12}$/D', $value) || strlen($value) >= 12)) $fail('Use a 6–12 digit PIN or a password of at least 12 characters.');
    }
}
