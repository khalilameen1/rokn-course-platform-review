<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Auth\AdminPermissionMatrix;
use App\Services\AdminTeacherReadService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TeacherProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware remains the owner of dashboard action permissions.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(AdminTeacherReadService $teachers): array
    {
        $creating = $this->isMethod('POST');
        $teacherId = $creating
            ? $teachers->createIntentTeacherId($this->input('authoring_request_id'))
            : $teachers->existingTeacherId((int) $this->route('teacher'));

        return [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'nullable|string|max:255',
            ...$this->credentialRules($teacherId),
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'job_title' => 'nullable|string|max:255',
            'bio_ar' => 'nullable|string',
            'bio_en' => 'nullable|string',
            'active' => 'nullable|boolean',
            ...($creating ? ['authoring_request_id' => 'required|uuid'] : ['editor_version' => 'required|string|size:64']),
        ];
    }

    public function managesCredentials(): bool
    {
        return app(AdminPermissionMatrix::class)->allowsCapability(
            $this->user()?->role, AdminPermissionMatrix::ACCOUNT_CREDENTIALS
        ) && $this->boolean('manage_credentials');
    }

    /** @return array<string, mixed> */
    public function profilePayload(): array
    {
        $payload = $this->safe()->only(['name_ar', 'name_en', 'job_title', 'bio_ar', 'bio_en']);
        if ($this->isMethod('POST')) {
            $payload['name_ar'] = trim((string) $this->input('name_ar'));
            $payload['name_en'] = $this->filled('name_en') ? trim((string) $this->input('name_en')) : null;
            $payload += ['email' => null, 'phone' => null, 'password' => null];
        }
        if ($this->managesCredentials()) {
            foreach (['email', 'phone', 'password'] as $field) {
                if ($this->filled($field)) {
                    $value = (string) $this->input($field);
                    $payload[$field] = match ($field) {
                        'email' => strtolower(trim($value)),
                        'phone' => trim($value),
                        default => $value,
                    };
                }
            }
        }

        return $payload;
    }

    /** @return array<string, list<mixed>> */
    private function credentialRules(?int $teacherId): array
    {
        if (! app(AdminPermissionMatrix::class)->allowsCapability(
            $this->user()?->role, AdminPermissionMatrix::ACCOUNT_CREDENTIALS
        )) {
            return array_fill_keys(
                ['manage_credentials', 'email', 'phone', 'password', 'password_confirmation'], ['prohibited']
            );
        }
        if (! $this->boolean('manage_credentials')) {
            return ['manage_credentials' => ['nullable', 'boolean']] + array_fill_keys(
                ['email', 'phone', 'password', 'password_confirmation'], ['exclude']
            );
        }

        return [
            'manage_credentials' => ['nullable', 'boolean'],
            'email' => ['nullable', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($teacherId)],
            'phone' => ['nullable', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($teacherId)],
            'password' => ['nullable', 'string', 'min:10', 'max:72', 'confirmed'],
            'password_confirmation' => ['nullable', 'same:password'],
        ];
    }
}
