<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\PaymentReconciliationFinding;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PaymentFindingIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'state' => ['nullable', Rule::in([
                PaymentReconciliationFinding::STATE_OPEN,
                PaymentReconciliationFinding::STATE_RESOLVED,
                PaymentReconciliationFinding::STATE_IGNORED,
            ])],
            'kind' => 'nullable|string|max:64',
            'order_ref' => 'nullable|string|max:191',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->query->has('state')) {
            $this->merge(['state' => PaymentReconciliationFinding::STATE_OPEN]);
        }
    }
}
