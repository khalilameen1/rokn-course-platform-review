<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class AdminMfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['code' => $this->routeIs('admin.mfa.setup.confirm')
            ? ['required', 'string', 'regex:/^[0-9]{6}$/D']
            : ['required', 'string', 'min:6', 'max:32']];
    }

    protected function failedValidation(Validator $validator): void
    {
        $this->forgetSubmittedCode();
        parent::failedValidation($validator);
    }

    protected function passedValidation(): void
    {
        // The validator retains the validated value for this action only.
        $this->forgetSubmittedCode();
    }

    private function forgetSubmittedCode(): void
    {
        $this->request->remove('code');
        // FormRequest is a copy. Laravel flashes input from the original request.
        $this->container->make('request')->request->remove('code');
    }
}
