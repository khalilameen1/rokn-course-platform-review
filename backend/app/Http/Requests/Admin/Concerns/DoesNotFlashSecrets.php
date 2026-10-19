<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Concerns;

use Illuminate\Contracts\Validation\Validator;

/** Keeps Laravel's validator response while removing secrets from both request copies. */
trait DoesNotFlashSecrets
{
    abstract protected function sensitiveInputFields(): array;

    protected function failedValidation(Validator $validator): void
    {
        $this->forgetSensitiveInput();
        parent::failedValidation($validator);
    }

    protected function passedValidation(): void
    {
        // Validated values remain available only through the validator in this action.
        $this->forgetSensitiveInput();
    }

    private function forgetSensitiveInput(): void
    {
        foreach ($this->sensitiveInputFields() as $field) {
            $this->request->remove($field);
            $this->container->make('request')->request->remove($field);
        }
    }
}
