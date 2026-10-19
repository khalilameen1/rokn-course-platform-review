<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ModeratorProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $moderator = $this->route('moderator');
        $isEdit = $moderator instanceof User;
        $manageCredentials = ! $isEdit || $this->boolean('manage_credentials');

        return [
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'manage_credentials' => $isEdit ? ['nullable', 'boolean'] : ['prohibited'],
            'email' => $manageCredentials
                ? ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($moderator?->id)]
                : ['exclude'],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('users', 'phone')->ignore($moderator?->id),
            ],
            'password' => $manageCredentials
                ? [$isEdit ? 'nullable' : 'required', 'string', 'min:10', 'confirmed']
                : ['exclude'],
            'password_confirmation' => $manageCredentials
                ? [$isEdit ? 'nullable' : 'required', 'same:password']
                : ['exclude'],
            'active' => ['nullable', 'boolean'],
            'authoring_request_id' => [$moderator ? 'nullable' : 'required', 'uuid'],
            'editor_version' => [$moderator ? 'required' : 'nullable', 'string', 'size:64'],
        ];
    }
}
