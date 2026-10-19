<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\ReportPeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProductAnalyticsIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'period' => ['nullable', Rule::in(array_keys(ReportPeriod::labels()))],
        ];
    }
}
