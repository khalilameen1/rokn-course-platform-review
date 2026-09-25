<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DesignSetting;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdminSingletonLock;
use App\Support\AppSettingsEditorVersion;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Application settings writes, including their dependent plan/device updates. */
final class AdminAppSettingsAuthoringService
{
    public function __construct(
        private readonly PublicAppSettingsService $publicSettings,
        private readonly CoursePlanAuthoringService $planAuthoring
    ) {
    }

    /** @param array<string, mixed> $validated Validated editor fields, including submitted secrets. */
    public function update(#[\SensitiveParameter] array $validated): void
    {
        if (array_key_exists('ai_plan_policy', $validated)) {
            $aiPlanPolicy = (array) $validated['ai_plan_policy'];
            if (array_diff(['basic', 'guided', 'mentor'], array_keys($aiPlanPolicy)) !== []) {
                throw ValidationException::withMessages([
                    'ai_plan_policy' => ['يجب إرسال إعدادات الاشتراكات الثلاثة كاملة'],
                ]);
            }
            foreach (['basic', 'guided', 'mentor'] as $code) {
                $tier = (array) $aiPlanPolicy[$code];
                $chatEnabled = $code !== 'basic' && !empty($tier['chat_enabled']);
                $chatLimit = max(0, (int) $tier['chat_message_limit']);
                $feedback = (string) $tier['project_feedback_level'];
                $followupLimit = max(0, (int) $tier['project_followup_message_limit']);
                $tierCeiling = max(0, (int) config(
                    "course_plans.ai_tiers.{$code}.chat_message_limit",
                    0
                ));

                if ($chatEnabled && $chatLimit === 0) {
                    throw ValidationException::withMessages([
                        "ai_plan_policy.{$code}.chat_message_limit" => 'حدد عدد الرسائل عند تشغيل الشات',
                    ]);
                }
                if ($chatLimit > $tierCeiling) {
                    throw ValidationException::withMessages([
                        "ai_plan_policy.{$code}.chat_message_limit" =>
                            "الحد الأقصى لهذه الفئة {$tierCeiling} رسالة",
                    ]);
                }
                if ($code === 'basic') {
                    $feedback = 'pass_only';
                } elseif ($code === 'guided' && $feedback === 'enhanced') {
                    throw ValidationException::withMessages([
                        "ai_plan_policy.{$code}.project_feedback_level" => 'المتابعة المتبادلة مخصصة لفئة التعلّم بمتابعة',
                    ]);
                }
                if ($feedback === 'enhanced' && $followupLimit === 0) {
                    throw ValidationException::withMessages([
                        "ai_plan_policy.{$code}.project_followup_message_limit" => 'حدد عدد رسائل المتابعة لهذه الفئة',
                    ]);
                }
                $followupCeiling = max(0, (int) config(
                    "course_plans.ai_tiers.{$code}.project_followup_message_limit",
                    0
                ));
                if ($followupLimit > $followupCeiling) {
                    throw ValidationException::withMessages([
                        "ai_plan_policy.{$code}.project_followup_message_limit" =>
                            "الحد الأقصى لهذه الفئة {$followupCeiling} رسالة",
                    ]);
                }

                $aiPlanPolicy[$code] = [
                    'chat_enabled' => $chatEnabled,
                    'chat_message_limit' => $chatEnabled ? $chatLimit : 0,
                    'chat_attachments_enabled' => $chatEnabled
                        && !empty($tier['chat_attachments_enabled']),
                    'project_feedback_level' => $feedback,
                    'project_followup_message_limit' => $feedback === 'enhanced'
                        ? $followupLimit : 0,
                ];
            }
            $validated['ai_plan_policy'] = $aiPlanPolicy;
        }

        $designFields = [
            'facebook_url',
            'youtube_url',
            'instagram_url',
            'tiktok_url',
            'telegram_url',
            'whatsapp_url',
        ];
        $designUpdates = Arr::only($validated, $designFields);
        $validated = Arr::except($validated, $designFields);
        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);

        foreach ($designUpdates as $field => $url) {
            if ($url === null || trim((string) $url) === '') {
                $designUpdates[$field] = null;
                continue;
            }
            $channel = str_replace('_url', '', $field);
            $normalized = $channel === 'whatsapp'
                ? $this->publicSettings->whatsAppUrl($url)
                : $this->publicSettings->socialUrl($channel, $url);
            if ($normalized === null) {
                throw ValidationException::withMessages([
                    $field => [$channel === 'whatsapp'
                        ? 'أدخل رقمًا دوليًا أو رابطًا صحيحًا يبدأ بـ https://wa.me/'
                        : 'أدخل رابط الحساب الصحيح لهذه المنصة يبدأ بـ https'],
                ]);
            }
            $designUpdates[$field] = $normalized;
        }

        $secretUpdates = [];
        if (!empty($validated['bunny_api_key'])) {
            $secretUpdates['bunny_api_key_secret'] = $validated['bunny_api_key'];
        }
        if (!empty($validated['bunny_storage_password'])) {
            $secretUpdates['bunny_storage_password_secret'] = $validated['bunny_storage_password'];
        }
        if (!empty($validated['bunny_security_key'])) {
            $secretUpdates['bunny_security_key_secret'] = $validated['bunny_security_key'];
        }
        unset($validated['bunny_api_key'], $validated['bunny_storage_password'], $validated['bunny_security_key']);

        if (!empty($validated['support_whatsapp_url'])) {
            $normalizedWhatsAppUrl = $this->publicSettings->whatsAppUrl($validated['support_whatsapp_url']);
            if ($normalizedWhatsAppUrl === null) {
                throw ValidationException::withMessages([
                    'support_whatsapp_url' => ['أدخل رقمًا دوليًا مثل +201001234567 أو رابطًا يبدأ بـ https://wa.me/.'],
                ]);
            }
            $validated['support_whatsapp_url'] = $normalizedWhatsAppUrl;
        }

        DB::transaction(function () use (
            $validated,
            $secretUpdates,
            $designUpdates,
            $editorVersion
        ): void {
            AdminSingletonLock::acquire('settings', 'design_settings');
            $settings = Setting::query()->lockForUpdate()->first();
            $design = DesignSetting::query()->lockForUpdate()->first();
            $settingsSnapshot = $settings ?? new Setting();
            $designSnapshot = $design ?? DesignSetting::getDefaultSettings();
            if (!hash_equals(
                AppSettingsEditorVersion::for($settingsSnapshot, $designSnapshot),
                $editorVersion
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّرت إعدادات التطبيق منذ فتح الصفحة\nأعد تحميلها قبل الحفظ",
                ]);
            }
            $settings ??= Setting::query()->create([]);
            $previousDevicePolicy = DeviceLoginService::normalizePolicy(
                $settings->device_login_policy
            );
            $settings->update($validated + $secretUpdates);
            $policy = (array) ($validated['ai_plan_policy'] ?? []);
            if ($policy !== []) {
                $this->planAuthoring->syncGlobalAiPolicy($policy);
            }
            if (
                array_key_exists('device_login_policy', $validated)
                && $validated['device_login_policy'] === DeviceLoginService::POLICY_MULTIPLE
                && $previousDevicePolicy !== DeviceLoginService::POLICY_MULTIPLE
                && Schema::hasColumn('users', 'locked_device_id')
            ) {
                User::query()->whereNotNull('locked_device_id')->update(['locked_device_id' => null]);
            }
            $design ??= DesignSetting::getDefaultSettings();
            $design->fill($designUpdates)->save();
        });
    }
}
