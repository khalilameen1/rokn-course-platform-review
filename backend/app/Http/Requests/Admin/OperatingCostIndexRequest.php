<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\OperatingCostPool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OperatingCostIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_key' => ['nullable', Rule::in(array_keys(OperatingCostPool::SERVICES))],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }
}
