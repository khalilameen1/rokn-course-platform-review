<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\DesignSetting;
use App\Models\Setting;
use App\Services\BunnyService;
use App\Services\AdminBunnyCleanupReviewService;
use App\Services\AdminAppSettingsAuthoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\AppSettingsEditorVersion;
use App\Auth\AdminSessionIdentity;

class SettingsController extends Controller
{
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
                $candidateQuery->whereIn('reason', AdminBunnyCleanupReviewService::VERIFIED_REASONS);
            } elseif ($cleanupFilter === 'failed') {
                $candidateQuery->whereNotNull('last_error');
            }
            $bunnyCleanupCandidates = $candidateQuery->latest('updated_at')
                ->limit(20)
                ->get();
        } else {
            $cleanupFilter = 'verified';
        }
        $editorVersion = AppSettingsEditorVersion::for($settings, $designSettings);

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
        BunnyVideoCleanupCandidate $candidate,
        AdminBunnyCleanupReviewService $reviews
    ) {
        if (!$reviews->approve((int) $candidate->id, (int) $request->user()->id)) {
            return redirect()->route('admin.settings')
                ->with('error', 'لا يمكن اعتماد الحذف لأن الفيديو ما زال مستخدمًا في قسم منشور');
        }

        return redirect()->route('admin.settings')
            ->with('success', 'تم اعتماد الفيديو للتنظيف بعد انتهاء فترة الاحتفاظ');
    }

    public function approveBunnyCleanupBatch(Request $request, AdminBunnyCleanupReviewService $reviews)
    {
        $validated = $request->validate([
            'cleanup_ids' => 'required|array|min:1|max:100',
            'cleanup_ids.*' => 'required|integer|distinct|exists:bunny_video_cleanup_candidates,id',
        ]);

        $result = $reviews->approveBatch($validated['cleanup_ids'], (int) $request->user()->id);
        $approved = $result['approved'];
        $skippedActive = $result['skipped_active'];

        $message = "تم اعتماد {$approved} فيديو للتنظيف بعد فترة الاحتفاظ";
        if ($skippedActive > 0) {
            $message .= " وتجاوز {$skippedActive} فيديو ما زال مستخدمًا";
        }

        return redirect()->route('admin.settings', ['cleanup_filter' => 'verified'])
            ->with($skippedActive > 0 ? 'warning' : 'success', $message);
    }

    /**
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(
        Request $request,
        AdminAppSettingsAuthoringService $authoring
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

        // Strip secrets from the request before any domain validation can fail,
        // so redirect validation never flashes them back into the session.
        $this->forgetBunnySecretInputs($request);
        $authoring->update($validated);
        return redirect()->route('admin.settings')->with('success', 'تم التحديث بنجاح');
    }

    private function forgetBunnySecretInputs(Request $request): void
    {
        foreach (['bunny_api_key', 'bunny_storage_password', 'bunny_security_key', 'api_key'] as $field) {
            $request->request->remove($field);
        }
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
