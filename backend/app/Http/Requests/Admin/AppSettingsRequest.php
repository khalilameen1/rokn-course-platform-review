<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\DoesNotFlashSecrets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AppSettingsRequest extends FormRequest
{
    use DoesNotFlashSecrets;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'site_name_ar' => 'nullable|string|max:255',
            'site_name_en' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'direct_checkout_discount_percent' => 'required|numeric|min:0|max:50',
            'seo_meta_title_ar' => 'nullable|string|max:255',
            'seo_meta_description_ar' => 'nullable|string|max:500',
            'seo_meta_title_en' => 'nullable|string|max:255',
            'seo_meta_description_en' => 'nullable|string|max:500',
            'english_translation' => 'nullable|boolean',
            'device_login_policy' => 'nullable|in:multiple_devices,single_device,single_device_permanent',
            'bunny_enabled' => 'nullable|boolean',
            'bunny_api_key' => 'nullable|string|max:4096',
            'bunny_library_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],
            'bunny_cdn_hostname' => ['nullable', 'string', 'max:253', 'regex:/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i'],
            'bunny_storage_zone_name' => 'nullable|string',
            'bunny_storage_password' => 'nullable|string|max:4096',
            'bunny_security_key' => 'nullable|string|max:4096',
            'support_whatsapp_url' => 'nullable|string|max:255',
            'facebook_url' => 'nullable|url|starts_with:https://|max:2048',
            'youtube_url' => 'nullable|url|starts_with:https://|max:2048',
            'instagram_url' => 'nullable|url|starts_with:https://|max:2048',
            'tiktok_url' => 'nullable|url|starts_with:https://|max:2048',
            'telegram_url' => 'nullable|url|starts_with:https://|max:2048',
            'whatsapp_url' => 'nullable|string|max:2048',
            'ai_global_daily_request_limit' => 'sometimes|required|integer|min:1|max:10000000',
            'ai_global_daily_token_budget' => 'sometimes|required|integer|min:1000|max:1000000000',
            'ai_global_monthly_token_budget' => 'sometimes|required|integer|min:1000|max:10000000000',
            'ai_plan_policy' => 'sometimes|required|array:basic,guided,mentor',
            'ai_plan_policy.*.chat_enabled' => 'nullable|boolean',
            'ai_plan_policy.*.chat_message_limit' => 'required|integer|min:0|max:'
                .max(1, (int) config('course_plans.ai_tiers.mentor.chat_message_limit', 150)),
            'ai_plan_policy.*.chat_attachments_enabled' => 'nullable|boolean',
            'ai_plan_policy.*.project_feedback_level' => ['required', Rule::in(['pass_only', 'report', 'enhanced'])],
            'ai_plan_policy.*.project_followup_message_limit' => 'required|integer|min:0|max:'
                .max(1, (int) config('course_plans.ai_tiers.mentor.project_followup_message_limit', 50)),
            'editor_version' => 'required|string|size:64',
        ];
    }

    protected function sensitiveInputFields(): array
    {
        return ['bunny_api_key', 'bunny_storage_password', 'bunny_security_key', 'api_key'];
    }
}
