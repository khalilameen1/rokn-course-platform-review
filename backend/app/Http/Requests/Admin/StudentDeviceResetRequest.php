<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class StudentDeviceResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state_version' => ['required', 'string', 'size:64'],
            'expected_policy' => ['required', 'string'],
        ];
    }
}
