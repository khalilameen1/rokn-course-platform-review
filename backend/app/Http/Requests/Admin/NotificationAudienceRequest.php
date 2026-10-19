<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class NotificationAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audience' => ['required', 'string', 'in:all,not_enrolled,enrolled'],
            'course_id' => ['nullable', 'required_unless:audience,all', 'integer', 'exists:courses,id'],
            'notification_kind' => ['nullable', 'string', 'in:marketing,service'],
        ];
    }
}
