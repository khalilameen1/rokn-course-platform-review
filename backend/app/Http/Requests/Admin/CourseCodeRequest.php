<?php

namespace App\Http\Requests\Admin;

use App\Support\BusinessClock;
use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;

class CourseCodeRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $normalized = [];
        if ($this->input('name') !== null) {
            $normalized['name'] = UnicodeText::clean($this->input('name'), false);
        }
        if ($this->input('description') !== null) {
            $normalized['description'] = UnicodeText::clean($this->input('description'));
        }
        if ($normalized !== []) $this->merge($normalized);
    }

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
        $isUpdating = $this->isMethod('put') || $this->isMethod('patch');
        
        $rules = [
            'name' => 'nullable|string|max:255',
            // Partial lesson codes previously created a whole-course
            // enrollment. Until entitlement scopes exist, only honest
            // course-level codes are accepted.
            'type' => 'required|in:course',
            'start_date' => 'nullable|date',
            'expiry_date' => 'nullable|date|after:start_date',
            'max_uses' => 'required|integer|min:1|max:10000',
            'description' => 'nullable|string|max:1000',
            'allowed_email_domains' => 'nullable|string|max:2000',
            'is_grant' => 'nullable|boolean',
        ];

        // number_of_codes is only required when creating new codes
        if (!$isUpdating) {
            $rules['number_of_codes'] = 'required|integer|min:1|max:100';
            $rules['authoring_request_id'] = 'required|uuid';
        } else {
            $rules['authoring_request_id'] = 'nullable|uuid';
        }

        // Add is_active for update
        if ($isUpdating) {
            $rules['is_active'] = 'nullable|boolean';
            $rules['editor_version'] = 'required|string|size:64';
        }

        $rules['course_id'] = 'required|exists:courses,id';

        if ($this->boolean('is_grant')) {
            $rules['type'] = 'required|in:course';
        }

        return $rules;
    }

    public function withValidator($validator): void
    {
        if (!$this->isMethod('post')) {
            return;
        }
        $validator->after(function ($validator): void {
            $value = trim((string) $this->input('start_date'));
            if ($value === '' || $validator->errors()->has('start_date')) {
                return;
            }
            try {
                if (BusinessClock::localInputToUtc($value)?->lessThan(BusinessClock::utcNow()->startOfMinute())) {
                    $validator->errors()->add('start_date', 'تاريخ البداية يجب أن يكون الآن أو بعده');
                }
            } catch (\Throwable) {
                // The date rule owns malformed input.
            }
        });
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'type.required' => 'نوع الكود مطلوب',
            'type.in' => 'أكواد الإتاحة متاحة للدورة كاملة فقط',
            'course_id.required' => 'الدورة مطلوبة',
            'course_id.exists' => 'الدورة المحددة غير موجودة',
            'start_date.date' => 'تاريخ البداية يجب أن يكون تاريخ صحيح',
            'start_date.after_or_equal' => 'تاريخ البداية يجب أن يكون اليوم أو بعده',
            'expiry_date.date' => 'تاريخ الانتهاء يجب أن يكون تاريخ صحيح',
            'expiry_date.after' => 'تاريخ الانتهاء يجب أن يكون بعد تاريخ البداية',
            'max_uses.required' => 'عدد مرات الاستخدام مطلوب',
            'max_uses.integer' => 'عدد مرات الاستخدام يجب أن يكون رقم صحيح',
            'max_uses.min' => 'عدد مرات الاستخدام يجب أن يكون 1 على الأقل',
            'max_uses.max' => 'عدد مرات الاستخدام يجب أن يكون 10000 كحد أقصى',
            'number_of_codes.required' => 'عدد الأكواد المطلوب إنشاؤها مطلوب',
            'number_of_codes.integer' => 'عدد الأكواد يجب أن يكون رقم صحيح',
            'number_of_codes.min' => 'عدد الأكواد يجب أن يكون 1 على الأقل',
            'number_of_codes.max' => 'عدد الأكواد يجب أن يكون 100 كحد أقصى',
        ];
    }
}

