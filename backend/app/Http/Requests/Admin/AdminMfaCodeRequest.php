<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\DoesNotFlashSecrets;
use Illuminate\Foundation\Http\FormRequest;

final class AdminMfaCodeRequest extends FormRequest
{
    use DoesNotFlashSecrets;

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

    protected function sensitiveInputFields(): array
    {
        return ['code'];
    }
}
