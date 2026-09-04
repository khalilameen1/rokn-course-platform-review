<?php

namespace App\Http\Requests\Admin;

use App\Models\AdminNotification;
use App\Support\RoknAppLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminNotificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $notification = $this->route('admin_notification');
        if (!$notification && $this->isMethod('post')) {
            $notification = AdminNotification::query()
                ->where('authoring_request_id', (string) $this->input('authoring_request_id'))
                ->first();
        }

        return [
            'system_key' => [
                'nullable',
                'string',
                'max:80',
                'regex:/^[a-z0-9_]+$/',
                Rule::in(AdminNotification::SYSTEM_KEYS),
                Rule::unique('admin_notifications', 'system_key')->ignore($notification?->id),
            ],
            'surface' => ['required', Rule::in(array_keys(AdminNotification::SURFACES))],
            'title_ar' => ['required', 'string', 'min:3', 'max:80', $this->knownPlaceholders()],
            'title_en' => ['nullable', 'string', 'min:3', 'max:80', $this->knownPlaceholders()],
            'description_ar' => ['required', 'string', 'min:3', 'max:240', $this->knownPlaceholders()],
            'description_en' => ['nullable', 'string', 'min:3', 'max:240', $this->knownPlaceholders()],
            'action_label_ar' => 'nullable|string|max:80',
            'action_label_en' => 'nullable|string|max:80',
            'secondary_action_label_ar' => 'nullable|string|max:80',
            'secondary_action_label_en' => 'nullable|string|max:80',
            'link' => [
                'nullable',
                'string',
                'max:2000',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    $link = trim((string) $value);
                    if ($link === '') {
                        return;
                    }
                    if (RoknAppLink::normalize($link) === null) {
                        $fail('اختر وجهة صحيحة داخل ركن');
                    }
                },
            ],
            'is_active' => 'nullable|boolean',
            'is_dismissible' => 'nullable|boolean',
            'priority' => 'required|integer|min:0|max:1000',
            'cooldown_hours' => 'required|integer|min:0|max:8760',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'image' => 'nullable|image|max:4096',
            'remove_image' => 'nullable|boolean',
            'authoring_request_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'uuid'],
            'editor_version' => [$this->isMethod('put') || $this->isMethod('patch') ? 'required' : 'nullable', 'string', 'size:64'],
        ];
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'title_ar' => 'عنوان الإشعار',
            'title_en' => 'عنوان الإشعار بالإنجليزية',
            'description_ar' => 'نص الإشعار',
            'description_en' => 'نص الإشعار بالإنجليزية',
            'link' => 'وجهة زر الإشعار',
            'image' => 'صورة الإشعار',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $systemKey = trim((string) $this->input('system_key'));
            $surface = trim((string) $this->input('surface'));
            if ($systemKey === '' && $surface !== 'announcement') {
                $validator->errors()->add(
                    'surface',
                    'الإعلان اليدوي يظهر في مساحة الإعلانات فقط'
                );
            }

            if (!$this->boolean('is_active')) {
                return;
            }

            $hasLink = RoknAppLink::normalize($this->input('link')) !== null;
            $hasArabicAction = trim((string) $this->input('action_label_ar')) !== '';
            if ($hasLink && !$hasArabicAction) {
                $validator->errors()->add('action_label_ar', 'اكتب نص الزر الذي سيفتح الوجهة');
            } elseif (!$hasLink && $hasArabicAction) {
                $validator->errors()->add('link', 'اختر وجهة للزر أو احذف نصه');
            }
        });
    }

    private function knownPlaceholders(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            preg_match_all('/\{([^{}\r\n]+)\}/', (string) $value, $matches);
            $unknown = array_diff(array_unique($matches[1] ?? []), [
                'coins',
                'course',
                'task',
                'lesson',
                'project',
                'case',
            ]);
            if ($unknown !== []) {
                $fail('يوجد متغير غير معروف في النص');
            }
        };
    }
}
