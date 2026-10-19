<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ModeratorMfaResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'confirm_email' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['confirm_email.required' => 'اكتب بريد الحساب لتأكيد إعادة الضبط'];
    }
}
