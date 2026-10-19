<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\SupportCaseService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SupportCaseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:120',
            'status' => ['nullable', Rule::in(SupportCaseService::CUSTOMER_STATUSES)],
            'category' => ['nullable', Rule::in(['bug', 'suggestion', 'course_content', 'playback'])],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => 'nullable|integer|exists:users,id',
            'overdue' => 'nullable|boolean',
            'app_version' => 'nullable|string|max:32',
            'course_id' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }
}
