<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class ContactAccountDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'editor_version' => ['required', 'string', 'size:64'],
            'account_email' => ['required', 'string', 'max:255'],
            'verification_note' => ['required', 'string', 'min:8', 'max:500'],
            'confirm_identity' => ['accepted'],
            'confirm_delete' => ['accepted'],
        ];
    }

    public function messages(): array
    {
        return ['account_email.required' => 'اكتب بريد الحساب للتأكيد.', 'verification_note.required' => 'سجّل طريقة التحقق من صاحب الحساب.', 'verification_note.min' => 'اكتب ملاحظة تحقق أوضح.', 'confirm_identity.accepted' => 'أكد أنك تحققت من صاحب الحساب.', 'confirm_delete.accepted' => 'أكد تنفيذ الحذف النهائي.'];
    }
}
