<?php

namespace App\Http\Requests\Admin;

use App\Services\CertificateTextTemplateService;
use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    private const EDITABLE_PLAN_FIELDS = [
        'name_ar',
        'name_en',
        'price_coins',
        'minimum_paid_coins',
        'is_active',
        'certificate_enabled',
    ];

    protected function prepareForValidation(): void
    {
        $singleLine = [
            'name_ar', 'name_en', 'attachment_prompt_title',
            'attachment_prompt_button_text', 'catalog_badge_ar',
            'catalog_badge_en', 'search_keywords_ar', 'search_keywords_en',
        ];
        $multiline = ['description_ar', 'description_en', 'attachment_prompt_body'];
        $normalized = [];
        foreach ($singleLine as $field) {
            if ($this->has($field)) {
                $normalized[$field] = UnicodeText::clean($this->input($field), false);
            }
        }
        foreach ($multiline as $field) {
            if ($this->has($field)) {
                $normalized[$field] = UnicodeText::clean($this->input($field));
            }
        }
        $plans = $this->input('access_plans');
        if (is_array($plans)) {
            foreach (['basic', 'guided', 'mentor'] as $code) {
                if (!is_array($plans[$code] ?? null)) continue;
                // Runtime AI policy is neither displayed nor owned here. Drop
                // stale or crafted fields before validation; the plan service
                // reloads its authoritative runtime values under the DB lock.
                $plans[$code] = array_intersect_key(
                    $plans[$code],
                    array_flip(self::EDITABLE_PLAN_FIELDS)
                );
                foreach (['name_ar', 'name_en'] as $field) {
                    if (array_key_exists($field, $plans[$code])) {
                        $plans[$code][$field] = UnicodeText::clean(
                            $plans[$code][$field],
                            false
                        );
                    }
                }
            }
            $normalized['access_plans'] = $plans;
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
        $certificateTemplateKeys = app(CertificateTextTemplateService::class)->keys();
        $updating = $this->isMethod('patch') || $this->isMethod('put');

        return [
            'name_ar' => $updating
                ? ['sometimes', 'required', 'string', 'min:3', 'max:255']
                : ['required', 'string', 'min:3', 'max:255'],
            'authoring_version' => [$updating ? 'required' : 'nullable', 'integer', 'min:1'],
            'authoring_request_id' => [$this->isMethod('post') ? 'required' : 'nullable', 'uuid'],
            'name_en' => 'nullable|string|max:255',
            'description_ar' => 'nullable|string|max:6000',
            'description_en' => 'nullable|string|max:6000',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:6144|dimensions:min_width=640,min_height=360,max_width=5000,max_height=5000',
            'grant_chat_attachments_to_current_enrollments' => 'nullable|boolean',
            'grant_project_followup_attachments_to_current_enrollments' => 'nullable|boolean',
            'attachment_prompt_enabled' => 'nullable|boolean',
            'attachment_prompt_at_seconds' => 'required_if:attachment_prompt_enabled,1|nullable|integer|min:0|max:3600',
            'attachment_prompt_frequency' => [
                'required_if:attachment_prompt_enabled,1',
                'nullable',
                Rule::in(array_keys((array) config('course_attachments.prompt.frequencies', []))),
            ],
            'attachment_prompt_title' => 'nullable|string|max:120',
            'attachment_prompt_body' => 'nullable|string|max:500',
            'attachment_prompt_button_text' => 'nullable|string|max:80',
            'path_id' => 'nullable|exists:paths,id',
            'publishing_intent' => ['nullable', Rule::in(['save', 'publish'])],
            'is_catalog_visible' => 'nullable|boolean',
            'is_main_course' => 'nullable|boolean',
            // Older dashboard/API callers may omit this newly introduced field;
            // the database has a safe default of 100.  When it is supplied it
            // must still be a concrete, bounded integer (never null).
            'home_sort_order' => 'sometimes|required|integer|min:0|max:10000',
            'catalog_badge_ar' => 'nullable|string|max:40',
            'catalog_badge_en' => 'nullable|string|max:40',
            'catalog_badge_tone' => ['nullable', Rule::in(['blue', 'green', 'gold', 'neutral'])],
            'search_keywords_ar' => 'nullable|string|max:2000',
            'search_keywords_en' => 'nullable|string|max:2000',
            'level_id' => 'nullable|required_if:awards_badge,1|exists:levels,id',
            'awards_badge' => 'nullable|boolean',
            'badge_track' => 'nullable|required_if:awards_badge,1|in:professional,freelance',
            'certificate_text_template_key' => $updating
                ? ['sometimes', 'required', Rule::in($certificateTemplateKeys)]
                : ['required', Rule::in($certificateTemplateKeys)],
            'classification_ids' => 'nullable|array|max:12',
            'classification_ids.*' => 'integer|distinct|exists:classifications,id',
            'classification_ids_present' => 'nullable|boolean',
            'teacher_ids' => 'nullable|array|max:10',
            'teacher_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['teacher', 'admin'])),
            ],
            'teacher_ids_present' => 'nullable|boolean',
            'access_plans' => 'nullable|array:basic,guided,mentor',
            'access_plans.basic' => 'required_with:access_plans|array',
            'access_plans.guided' => 'required_with:access_plans|array',
            'access_plans.mentor' => 'required_with:access_plans|array',
            'access_plans.*' => 'array:'.implode(',', self::EDITABLE_PLAN_FIELDS),
            'access_plans.*.name_ar' => 'required_with:access_plans|string|max:120',
            'access_plans.*.name_en' => 'nullable|string|max:120',
            'access_plans.*.price_coins' => 'required_with:access_plans|integer|min:0|max:100000000',
            'access_plans.*.minimum_paid_coins' => 'required_with:access_plans|integer|min:0|max:100000000',
            'access_plans.*.is_active' => 'nullable|boolean',
            'access_plans.*.certificate_enabled' => 'nullable|boolean',
        ];
    }

}
