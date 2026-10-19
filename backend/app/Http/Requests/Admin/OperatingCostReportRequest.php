<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OperatingCostReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['nullable', Rule::in(array_keys(ReportPeriod::labels()))],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'plan' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', Rule::in([
                'purchase', 'grant', 'course_code', 'grant_plus_purchase', 'code_plus_purchase',
            ])],
            'q' => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', Rule::in([20, 30, 50, 100])],
        ];
    }
}
