<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class KnownNotificationPlaceholders implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        preg_match_all('/\{([^{}\r\n]+)\}/', (string) $value, $matches);
        if (array_diff(array_unique($matches[1]), ['coins', 'course', 'task', 'lesson', 'project', 'case']) !== []) {
            $fail('يوجد متغير غير معروف في النص');
        }
    }
}
