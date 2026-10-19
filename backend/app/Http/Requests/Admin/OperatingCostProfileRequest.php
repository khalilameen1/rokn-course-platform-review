<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\OperatingCostPool;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OperatingCostProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'service_key' => ['required', Rule::in(array_keys(OperatingCostPool::SERVICES))],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'currency' => ['required', Rule::in(['EGP', 'USD'])],
            'fx_rate_to_egp' => ['nullable', 'required_if:currency,USD', 'numeric', 'min:0.0001', 'max:10000'],
            'allocation_driver' => ['required', Rule::in(array_keys(OperatingCostPool::DRIVERS))],
            'is_final' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'authoring_request_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'uuid'],
        ] + ['editor_version' => [$this->isMethod('post') ? 'nullable' : 'required', 'string', 'size:64']];
    }

    public function authoringData(): array
    {
        $data = $this->validated();
        unset($data['authoring_request_id'], $data['editor_version']);
        $data['is_final'] = $this->boolean('is_final');

        return $data;
    }
}
