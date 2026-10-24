<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\SupportAssignee;
use App\Services\SupportCaseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SupportCaseStateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => 'required|integer|min:1',
            'status' => ['required', Rule::in(SupportCaseService::CUSTOMER_STATUSES)],
            'priority' => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['nullable', 'integer', new SupportAssignee],
            'resolution_kind' => ['nullable', Rule::in(['fixed', 'guidance', 'compensated', 'not_reproducible', 'duplicate'])],
        ];
    }
}
