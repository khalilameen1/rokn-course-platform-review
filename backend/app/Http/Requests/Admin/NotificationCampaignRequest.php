<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\RoknNotificationDestination;
use Illuminate\Foundation\Http\FormRequest;

final class NotificationCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'title_ar' => ['required', 'string', 'max:80'],
            'message_ar' => ['required', 'string', 'max:240'],
            'title_en' => ['nullable', 'string', 'max:80'],
            'message_en' => ['nullable', 'string', 'max:240'],
            'course_id' => ['nullable', 'required_unless:audience,all', 'integer', 'exists:courses,id'],
            'audience' => ['required', 'string', 'in:all,not_enrolled,enrolled'],
            'notification_kind' => ['nullable', 'string', 'in:marketing,service'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
            'action_label' => ['nullable', 'string', 'max:40'],
            'action_link' => [
                'nullable',
                'string',
                'max:2000',
                new RoknNotificationDestination,
            ],
            'send_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
        ];

        return $rules + ($this->routeIs('admin.notifications.test')
            ? ['test_email' => ['required', 'email:rfc', 'max:255']]
            : [
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'authoring_request_id' => ['required', 'uuid'],
                // Pre-send recipient confirmation remains mandatory for broadcasts.
                'confirm_send' => ['exclude_with:user_id', 'accepted'],
            ]);
    }

    public function messages(): array
    {
        return [
            'confirm_send.accepted' => 'راجع عدد المستلمين وأكّد الإرسال',
            'test_email.required' => 'اكتب بريد حساب الطالب الذي سيستلم التجربة',
        ];
    }
}
