<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ContactCloseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'editor_version' => ['required', 'string', 'size:64'],
            'outcome' => ['required', 'in:self_service_completed,no_account_found,duplicate,withdrawn'],
            'resolution_note' => ['nullable', 'string', 'max:500'],
            'confirm_close' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return ['outcome.required' => 'اختر نتيجة المعالجة.', 'outcome.in' => 'نتيجة المعالجة غير صالحة.', 'confirm_close.accepted' => 'أكد أنك راجعت الطلب قبل إغلاقه.'];
    }
}
