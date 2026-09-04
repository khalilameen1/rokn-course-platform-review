<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'state' => ['nullable', Rule::in([
                'created', 'pending', 'paid', 'failed', 'expired', 'cancelled',
            ])],
            'payment_method' => ['nullable', 'string', 'max:64'],
            'package_id' => ['nullable', 'integer', 'exists:packages,id'],
            'user_search' => ['nullable', 'string', 'max:120'],
            'course_search' => ['nullable', 'string', 'max:120'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'amount_min' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'amount_max' => ['nullable', 'numeric', 'gte:amount_min', 'max:9999999999.99'],
        ];
    }
}
