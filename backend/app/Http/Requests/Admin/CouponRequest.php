<?php

namespace App\Http\Requests\Admin;

use App\Models\Coupon;
use App\Support\BusinessClock;
use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CouponRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['name_ar', 'name_en'] as $field) {
            if ($this->input($field) !== null) {
                $this->merge([$field => UnicodeText::clean($this->input($field), false)]);
            }
        }
        if ($this->has('code')) {
            $this->merge(['code' => UnicodeText::identifier($this->input('code'))]);
        }
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
        $intentCouponId = $this->isMethod('post')
            ? Coupon::withTrashed()->where('authoring_request_id', (string) $this->input('authoring_request_id'))->value('id')
            : null;

        return [
            'name_ar' => 'required|string|min:2|max:100',
            'name_en' => 'nullable|string|max:100',
            'code' => [
                'required',
                'string',
                'min:3',
                'max:50',
                Rule::unique('coupons', 'code')->ignore($this->route('coupon') ?: $intentCouponId),
            ],
            'balance' => 'required|integer|between:1,100',
            'course_id' => 'nullable|integer|exists:courses,id',
            'starts_at' => 'nullable|date',
            'max_redemptions' => 'nullable|integer|min:1|max:10000000',
            'expiry_date' => 'required|date_format:Y-m-d',
            'active' => 'required|boolean',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'authoring_request_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'uuid'],
            'editor_version' => [$this->isMethod('put') || $this->isMethod('patch') ? 'required' : 'nullable', 'string', 'size:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($validator->errors()->has('expiry_date') || $validator->errors()->has('starts_at')) {
                return;
            }
            try {
                $expiryDay = BusinessClock::localDate((string) $this->input('expiry_date'));
                if ($this->isMethod('post') && $expiryDay->isBefore(BusinessClock::now()->startOfDay())) {
                    $validator->errors()->add('expiry_date', 'تاريخ الانتهاء يجب أن يكون اليوم أو بعده');
                }
                $startsAt = BusinessClock::localInputToUtc($this->input('starts_at'));
                if ($startsAt && ! $startsAt->lessThan($expiryDay->addDay()->startOfDay()->utc())) {
                    $validator->errors()->add('starts_at', 'وقت البداية يجب أن يسبق نهاية الكوبون');
                }
            } catch (\Throwable) {
                // Date validation owns malformed input.
            }
        });
    }

    /**
     * @return array
     */
    public function attributes()
    {
        return [
            'code' => 'كود الكوبون',
            'balance' => 'نسبة الخصم',
            'expiry_date' => 'تاريخ الانتهاء',

        ];
    }
}
