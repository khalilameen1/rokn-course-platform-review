<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\CourseSection;
use App\Models\DesignSetting;
use App\Models\Lesson;
use App\Models\Setting;
use App\Models\User;
use App\Services\BunnyService;
use App\Services\CourseAccessPlanService;
use App\Services\DeviceLoginService;
use App\Services\PublicAppSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\AdminSingletonLock;
use App\Auth\AdminSessionIdentity;

class SettingsController extends Controller
{
    private const VERIFIED_CLEANUP_REASONS = [
        'publish_race_or_failure',
        'superseded_video',
        'unpublished_upload',
        'section_create_rollback',
        'section_update_rollback',
        'section_type_changed',
        'section_deleted',
    ];

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Keep page reads idempotent. Database defaults are persisted only by
        // the explicit POST below, not by opening the settings screen.
        $settings = Setting::query()->first() ?? new Setting();
        $designSettings = DesignSetting::getDefaultSettings();
        $bunnyCleanupCandidates = collect();
        $bunnyCleanupStats = ['pending_review' => 0, 'approved' => 0, 'deleted' => 0];
        if (Schema::hasTable('bunny_video_cleanup_candidates')) {
            $base = BunnyVideoCleanupCandidate::query();
            $bunnyCleanupStats = [
                'pending_review' => (clone $base)->whereNull('remote_deleted_at')->whereNull('reviewed_at')->count(),
                'approved' => (clone $base)->whereNull('remote_deleted_at')->whereNotNull('reviewed_at')->count(),
                'deleted' => (clone $base)->whereNotNull('remote_deleted_at')->count(),
            ];
            $cleanupFilter = (string) $request->query('cleanup_filter', 'verified');
            $candidateQuery = BunnyVideoCleanupCandidate::query();
            if ($cleanupFilter === 'verified') {
                $candidateQuery->whereIn('reason', self::VERIFIED_CLEANUP_REASONS);
            } elseif ($cleanupFilter === 'failed') {
                $candidateQuery->whereNotNull('last_error');
            }
            $bunnyCleanupCandidates = $candidateQuery->latest('updated_at')
                ->limit(20)
                ->get();
        } else {
            $cleanupFilter = 'verified';
        }
        $editorVersion = $this->settingsEditorVersion($settings, $designSettings);

        return view('admin.settings.index', compact(
            'settings',
            'designSettings',
            'bunnyCleanupCandidates',
            'bunnyCleanupStats',
            'cleanupFilter',
            'editorVersion'
        ));
    }

    public function approveBunnyCleanup(
        Request $request,
        BunnyVideoCleanupCandidate $candidate
    ) {
        abort_if($candidate->remote_deleted_at, 409, 'تم حذف هذا الفيديو بالفعل');

        $activeReference = CourseSection::query()
            ->join('lessons', function ($join): void {
                $join->on('lessons.id', '=', 'course_sections.sectionable_id')
                    ->where('course_sections.sectionable_type', '=', Lesson::class);
            })
            ->where('lessons.bunny_video_id', $candidate->video_guid)
            ->exists();
        if ($activeReference) {
            return redirect()->route('admin.settings')
                ->with('error', 'لا يمكن اعتماد الحذف لأن الفيديو ما زال مستخدمًا في قسم منشور');
        }

        $candidate->forceFill([
            'reviewed_at' => now(),
            'reviewed_by' => $request->user()->id,
            'requires_review' => false,
            'eligible_after' => $candidate->eligible_after->isFuture()
                ? $candidate->eligible_after
                : now(),
            'last_error' => null,
        ])->save();

        return redirect()->route('admin.settings')
            ->with('success', 'تم اعتماد الفيديو للتنظيف بعد انتهاء فترة الاحتفاظ');
    }

    public function approveBunnyCleanupBatch(Request $request)
    {
        $validated = $request->validate([
            'cleanup_ids' => 'required|array|min:1|max:100',
            'cleanup_ids.*' => 'required|integer|distinct|exists:bunny_video_cleanup_candidates,id',
        ]);

        $approved = 0;
        $skippedActive = 0;
        DB::transaction(function () use ($validated, $request, &$approved, &$skippedActive): void {
            $candidates = BunnyVideoCleanupCandidate::query()
                ->whereIn('id', $validated['cleanup_ids'])
                ->whereNull('remote_deleted_at')
                ->whereNull('reviewed_at')
                ->lockForUpdate()
                ->get();

            foreach ($candidates as $candidate) {
                if ($this->bunnyVideoHasActiveReference($candidate->video_guid)) {
                    $skippedActive++;
                    continue;
                }

                $candidate->forceFill([
                    'reviewed_at' => now(),
                    'reviewed_by' => $request->user()->id,
                    'requires_review' => false,
                    'eligible_after' => $candidate->eligible_after->isFuture()
                        ? $candidate->eligible_after
                        : now(),
                    'last_error' => null,
                ])->save();
                $approved++;
            }
        });

        $message = "تم اعتماد {$approved} فيديو للتنظيف بعد فترة الاحتفاظ";
        if ($skippedActive > 0) {
            $message .= " وتجاوز {$skippedActive} فيديو ما زال مستخدمًا";
        }

        return redirect()->route('admin.settings', ['cleanup_filter' => 'verified'])
            ->with($skippedActive > 0 ? 'warning' : 'success', $message);
    }

    private function bunnyVideoHasActiveReference(string $videoGuid): bool
    {
        return CourseSection::query()
            ->join('lessons', function ($join): void {
                $join->on('lessons.id', '=', 'course_sections.sectionable_id')
                    ->where('course_sections.sectionable_type', '=', Lesson::class);
            })
            ->where('lessons.bunny_video_id', $videoGuid)
            ->exists();
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(
        Request $request,
        PublicAppSettingsService $publicSettings,
        CourseAccessPlanService $accessPlans
    )
    {
        try {
            $validated = $request->validate([
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
            'enforce_course_section_order' => 'nullable|boolean',
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
                . max(1, (int) config('course_plans.ai_tiers.mentor.chat_message_limit', 150)),
            'ai_plan_policy.*.chat_attachments_enabled' => 'nullable|boolean',
            'ai_plan_policy.*.project_feedback_level' => ['required', Rule::in(['pass_only', 'report', 'enhanced'])],
            'ai_plan_policy.*.project_followup_message_limit' => 'required|integer|min:0|max:'
                . max(1, (int) config('course_plans.ai_tiers.mentor.project_followup_message_limit', 50)),
            'editor_version' => 'required|string|size:64',
            ]);
        } catch (ValidationException $exception) {
            $this->forgetBunnySecretInputs($request);
            throw $exception;
        }

        if (array_key_exists('ai_plan_policy', $validated)) {
            $aiPlanPolicy = (array) $validated['ai_plan_policy'];
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
                ? $publicSettings->whatsAppUrl($url)
                : $publicSettings->socialUrl($channel, $url);
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
        $this->forgetBunnySecretInputs($request);

        if (!empty($validated['support_whatsapp_url'])) {
            $normalizedWhatsAppUrl = $publicSettings->whatsAppUrl($validated['support_whatsapp_url']);
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
            $editorVersion,
            $accessPlans
        ): void {
            AdminSingletonLock::acquire('settings', 'design_settings');
            $settings = Setting::query()->lockForUpdate()->first();
            $design = DesignSetting::query()->lockForUpdate()->first();
            $settingsSnapshot = $settings ?? new Setting();
            $designSnapshot = $design ?? DesignSetting::getDefaultSettings();
            if (!hash_equals(
                $this->settingsEditorVersion($settingsSnapshot, $designSnapshot),
                $editorVersion
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت إعدادات التطبيق منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                ]);
            }
            $settings ??= Setting::query()->create([]);
            $previousDevicePolicy = DeviceLoginService::normalizePolicy(
                $settings->device_login_policy
            );
            $settings->update($validated + $secretUpdates);
            $policy = (array) ($validated['ai_plan_policy'] ?? []);
            if ($policy !== []) {
                $accessPlans->syncGlobalAiPolicy($policy);
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
        return redirect()->route('admin.settings')->with('success', 'تم التحديث بنجاح');
    }

    private function forgetBunnySecretInputs(Request $request): void
    {
        foreach (['bunny_api_key', 'bunny_storage_password', 'bunny_security_key', 'api_key'] as $field) {
            $request->request->remove($field);
        }
    }

    private function settingsEditorVersion(
        Setting $settings,
        DesignSetting $design
    ): string {
        $secretRevision = hash('sha256', json_encode([
            (string) $settings->getRawOriginal('bunny_api_key_secret'),
            (string) $settings->getRawOriginal('bunny_storage_password_secret'),
            (string) $settings->getRawOriginal('bunny_security_key_secret'),
        ], JSON_UNESCAPED_SLASHES));
        $settingValues = Arr::except($settings->getAttributes(), [
            'bunny_api_key_secret',
            'bunny_storage_password_secret',
            'bunny_security_key_secret',
            'bunny_api_key',
            'bunny_storage_password',
            'created_at',
            'updated_at',
        ]);
        $settingValues['bunny_secrets_revision'] = $secretRevision;
        $designValues = Arr::except($design->getAttributes(), [
            'created_at',
            'updated_at',
        ]);
        ksort($settingValues);
        ksort($designValues);

        return hash('sha256', json_encode(
            [$settingValues, $designValues],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    public function adminData()
    {
        return view('admin.settings.admin_data');
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateAdminData(Request $request, AdminSessionIdentity $sessionIdentity)
    {
        $user = $request->user();
        abort_unless($user, 403);

        $validated = $request->validate([
            'email' => [
                'required', 'email:rfc', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'password' => ['nullable', 'string', 'min:10', 'max:72'],
        ]);

        // This form only updates the administrator's login credentials.
        $user->email = strtolower(trim($validated['email']));
        if (!empty($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        $user->save();
        $request->session()->put(
            AdminSessionIdentity::SESSION_KEY,
            $sessionIdentity->fingerprint($user)
        );

        return redirect()->route('admin.admin_data')->with('success', 'تم التعديل بنجاح');
    }

    /**
     * Test Bunny.net connection
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function testBunnyConnection(Request $request)
    {
        try {
            $request->validate([
                'api_key' => 'nullable|string|max:4096',
                'library_id' => ['nullable', 'string', 'max:40', 'regex:/^\d+$/'],
            ]);
        } catch (ValidationException $exception) {
            $this->forgetBunnySecretInputs($request);
            throw $exception;
        }

        $submittedApiKey = $request->input('api_key');
        $this->forgetBunnySecretInputs($request);
        $settings = Setting::first();
        $apiKey = $submittedApiKey
            ?: config('bunny.stream_api_key')
            ?: $settings?->bunny_api_key_secret;
        $libraryId = $request->input('library_id')
            ?: config('bunny.library_id')
            ?: $settings?->bunny_library_id;
        if (!$apiKey || !$libraryId) {
            return response()->json(['success' => false, 'message' => 'بيانات Bunny غير مكتملة.'], 422);
        }

        $result = BunnyService::testConnection(
            $apiKey,
            $libraryId
        );

        return response()->json($result);
    }
}
