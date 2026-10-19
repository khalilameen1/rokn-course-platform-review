<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class SupportCaseCompensationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'amount' => 'required|integer|min:1|max:100000000',
            'note' => 'required|string|min:8|max:1000',
        ];
    }
}
