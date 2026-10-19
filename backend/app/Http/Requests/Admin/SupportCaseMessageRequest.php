<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SupportCaseMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'client_request_id' => 'required|uuid',
            'visibility' => ['required', Rule::in(['customer', 'internal'])],
            'message' => 'required|string|min:2|max:4000',
        ];
    }
}
