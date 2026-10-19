<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\RoknAppLink;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class RoknNotificationDestination implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (filled($value) && RoknAppLink::normalize($value) === null) {
            $fail('اختر وجهة صحيحة داخل ركن');
        }
    }
}
