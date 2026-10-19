<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\DoesNotFlashSecrets;
use Illuminate\Foundation\Http\FormRequest;

final class BunnyConnectionTestRequest extends FormRequest
{
    use DoesNotFlashSecrets;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'api_key' => 'nullable|string|max:4096',
            'library_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],
        ];
    }

    protected function sensitiveInputFields(): array
    {
        return ['bunny_api_key', 'bunny_storage_password', 'bunny_security_key', 'api_key'];
    }
}
