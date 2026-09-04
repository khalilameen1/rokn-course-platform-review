<?php

namespace App\Http\Requests\Admin;

use App\Models\Course;
use App\Services\CertificateTextTemplateService;
use App\Services\CourseAccessPlanService;
use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CourseRequest extends FormRequest
{
    private const PROTECTED_PLAN_RUNTIME_FIELDS = [
        'chat_enabled',
        'chat_message_limit',
        'chat_token_budget',
        'project_feedback_token_budget',
        'project_followup_message_limit',
        'project_followup_token_budget',
        'max_output_tokens',
        'project_feedback_level',
        'ai_budget_usd',
        'request_reserve_usd',
        'project_feedback_budget_usd',
        'project_feedback_reserve_usd',
        'project_followup_budget_usd',
        'project_followup_reserve_usd',
        'model_override',
        'chat_attachments_enabled',
        'chat_attachment_max_files',
        'project_followup_attachments_enabled',
        'project_followup_attachment_max_files',
        'project_output_enabled',
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
            // Course authoring owns names, prices and availability only.
            // Preserve the global AI policy fields against missing or crafted
            // values so an ordinary course save cannot rewrite operations policy.
            $course = $this->route('course');
            if ($course instanceof Course) {
                $protectedPlans = app(CourseAccessPlanService::class)
                    ->plansForEditor($course)
                    ->keyBy('code');
                foreach (['basic', 'guided', 'mentor'] as $code) {
                    $protected = $protectedPlans->get($code);
                    if (!$protected || !is_array($plans[$code] ?? null)) continue;
                    foreach (self::PROTECTED_PLAN_RUNTIME_FIELDS as $field) {
                        // These fields are owned by the global administrator
                        // policy and cannot be overwritten by course authoring.
                        $plans[$code][$field] = $protected->getAttribute($field);
                    }
                }
            }
            foreach (['basic', 'guided', 'mentor'] as $code) {
                if (!is_array($plans[$code] ?? null)) continue;
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

        return [
            'name_ar' => 'required|string|min:3|max:255',
            'authoring_version' => [$this->isMethod('patch') || $this->isMethod('put') ? 'required' : 'nullable', 'integer', 'min:1'],
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
            'price' => 'nullable|integer|min:0|max:100000000',
            'is_coming_soon' => 'nullable|boolean',
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
            'certificate_text_template_key' => [
                'required',
                Rule::in($certificateTemplateKeys),
            ],
            'classification_ids' => 'nullable|array|max:12',
            'classification_ids.*' => 'integer|distinct|exists:classifications,id',
            'teacher_ids' => 'nullable|array|max:10',
            'teacher_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('users', 'id')->where(fn ($query) => $query->whereIn('role', ['teacher', 'admin'])),
            ],
            'access_plans' => 'nullable|array:basic,guided,mentor',
            'access_plans.basic' => 'required_with:access_plans|array',
            'access_plans.guided' => 'required_with:access_plans|array',
            'access_plans.mentor' => 'required_with:access_plans|array',
            'access_plans.*' => 'array',
            'access_plans.*.name_ar' => 'required_with:access_plans|string|max:120',
            'access_plans.*.name_en' => 'nullable|string|max:120',
            'access_plans.*.price_coins' => 'required_with:access_plans|integer|min:0|max:100000000',
            'access_plans.*.minimum_paid_coins' => 'required_with:access_plans|integer|min:0|max:100000000',
            'access_plans.*.is_active' => 'nullable|boolean',
            'access_plans.*.chat_enabled' => 'nullable|boolean',
            'access_plans.*.chat_message_limit' => 'nullable|integer|min:0|max:100000',
            'access_plans.*.chat_token_budget' => 'nullable|integer|min:0|max:1000000000',
            'access_plans.*.chat_attachments_enabled' => 'nullable|boolean',
            'access_plans.*.chat_attachment_max_files' => 'nullable|integer|min:0|max:5',
            'access_plans.*.project_followup_attachments_enabled' => 'nullable|boolean',
            'access_plans.*.project_followup_attachment_max_files' => 'nullable|integer|min:0|max:5',
            'access_plans.*.ai_budget_usd' => 'nullable|numeric|min:0|max:10000',
            'access_plans.*.request_reserve_usd' => 'nullable|numeric|min:0|max:1000',
            'access_plans.*.project_feedback_token_budget' => 'nullable|integer|min:0|max:1000000000',
            'access_plans.*.project_feedback_budget_usd' => 'nullable|numeric|min:0|max:10000',
            'access_plans.*.project_feedback_reserve_usd' => 'nullable|numeric|min:0|max:1000',
            'access_plans.*.project_followup_message_limit' => 'nullable|integer|min:0|max:100000',
            'access_plans.*.project_followup_token_budget' => 'nullable|integer|min:0|max:1000000000',
            'access_plans.*.project_followup_budget_usd' => 'nullable|numeric|min:0|max:10000',
            'access_plans.*.project_followup_reserve_usd' => 'nullable|numeric|min:0|max:1000',
            'access_plans.*.max_output_tokens' => 'nullable|integer|min:80|max:' . max(80, (int) config('openrouter.max_tokens', 800)),
            'access_plans.*.model_override' => [
                'nullable', 'string', 'max:190',
                Rule::in(array_values(array_filter(config('openrouter.allowed_models', [])))),
            ],
            'access_plans.*.project_feedback_level' => ['nullable', Rule::in(['pass_only', 'report', 'enhanced'])],
            'access_plans.*.project_output_enabled' => 'nullable|boolean',
            'access_plans.*.certificate_enabled' => 'nullable|boolean',
        ];
    }

}
