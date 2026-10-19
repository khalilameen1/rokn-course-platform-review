<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminSessionIdentity;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppSettingsRequest;
use App\Http\Requests\Admin\BunnyCleanupBatchRequest;
use App\Http\Requests\Admin\BunnyConnectionTestRequest;
use App\Http\Requests\Admin\StaffSelfAccountRequest;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\DesignSetting;
use App\Models\Setting;
use App\Services\AdminAppSettingsAuthoringService;
use App\Services\AdminBunnyCleanupReviewService;
use App\Services\BunnyService;
use App\Support\AppSettingsEditorVersion;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * @return Factory|View
     */
    public function index(Request $request)
    {
        // Keep page reads idempotent. Database defaults are persisted only by
        // the explicit POST below, not by opening the settings screen.
        $settings = Setting::query()->first() ?? new Setting;
        $designSettings = DesignSetting::getDefaultSettings();
        $editorVersion = AppSettingsEditorVersion::for($settings, $designSettings);

        return view('admin.settings.index', compact(
            'settings',
            'designSettings',
            'editorVersion'
        ));
    }

    public function approveBunnyCleanup(
        Request $request,
        BunnyVideoCleanupCandidate $candidate,
        AdminBunnyCleanupReviewService $reviews
    ) {
        if (! $reviews->approve((int) $candidate->id, (int) $request->user()->id)) {
            return redirect()->route('admin.settings')
                ->with('error', 'لا يمكن اعتماد الحذف لأن الفيديو ما زال مستخدمًا في قسم منشور');
        }

        return redirect()->route('admin.settings')
            ->with('success', 'تم اعتماد الفيديو للتنظيف بعد انتهاء فترة الاحتفاظ');
    }

    public function approveBunnyCleanupBatch(BunnyCleanupBatchRequest $request, AdminBunnyCleanupReviewService $reviews)
    {
        $validated = $request->validated();

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
     * @return RedirectResponse
     */
    public function update(
        AppSettingsRequest $request,
        AdminAppSettingsAuthoringService $authoring
    ) {
        $validated = $request->validated();
        $authoring->update($validated);

        return redirect()->route('admin.settings')->with('success', 'تم التحديث بنجاح');
    }

    public function adminData()
    {
        return view('admin.settings.admin_data');
    }

    /**
     * @return RedirectResponse
     */
    public function updateAdminData(StaffSelfAccountRequest $request, AdminSessionIdentity $sessionIdentity)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Re-authenticate before changing login credentials (Laravel's
        // current_password rule, as in Jetstream/Fortify password updates),
        // so an unattended session cannot take over the account.
        $validated = $request->validated();

        // This form only updates the administrator's login credentials.
        $user->email = strtolower(trim($validated['email']));
        if (! empty($validated['password'])) {
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
     * @return JsonResponse
     */
    public function testBunnyConnection(BunnyConnectionTestRequest $request)
    {
        $submittedApiKey = $request->validated('api_key');
        $settings = Setting::first();
        $apiKey = $submittedApiKey
            ?: config('bunny.stream_api_key')
            ?: $settings?->bunny_api_key_secret;
        $libraryId = $request->input('library_id')
            ?: config('bunny.library_id')
            ?: $settings?->bunny_library_id;
        if (! $apiKey || ! $libraryId) {
            return response()->json(['success' => false, 'message' => 'بيانات Bunny غير مكتملة.'], 422);
        }

        $result = BunnyService::testConnection(
            $apiKey,
            $libraryId
        );

        return response()->json($result);
    }
}
