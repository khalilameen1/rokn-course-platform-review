<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Validation\ValidationException;

/** Domain services use transport-independent names; Record forms live at data.*. */
final class FormValidation
{
    public static function forRecord(ValidationException $exception): ValidationException
    {
        $errors = [];
        foreach ($exception->errors() as $field => $messages) {
            $errors[str_starts_with($field, 'data.') ? $field : 'data.'.$field] = $messages;
        }

        return ValidationException::withMessages($errors);
    }
}
