<?php

namespace App\Http\Controllers\Admin;

use App\Auth\AdminSessionIdentity;
use App\Filament\Auth\EditStaffProfile;
use App\Filament\Pages\AppSettings;
use App\Filament\Resources\BunnyCleanupResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppSettingsRequest;
use App\Http\Requests\Admin\BunnyCleanupBatchRequest;
use App\Http\Requests\Admin\BunnyConnectionTestRequest;
use App\Http\Requests\Admin\StaffSelfAccountRequest;
use App\Models\BunnyVideoCleanupCandidate;
use App\Services\AdminAppSettingsAuthoringService;
use App\Services\AdminBunnyCleanupReviewService;
use App\Services\AdminBunnyConnectionService;
use App\Services\AdminSelfAccountAuthoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(Request $request): RedirectResponse
    {
        if ($request->has('cleanup_filter')) {
            return redirect(BunnyCleanupResource::getUrl('index', ['filters' => ['review' => ['value' => $request->query('cleanup_filter') === 'all' ? '' : $request->query('cleanup_filter')]]]));
        }

        return redirect(AppSettings::getUrl());
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
        return redirect(EditStaffProfile::getUrl());
    }

    /**
     * @return RedirectResponse
     */
    public function updateAdminData(StaffSelfAccountRequest $request, AdminSessionIdentity $sessionIdentity, AdminSelfAccountAuthoringService $authoring)
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Re-authenticate before changing login credentials (Laravel's
        // current_password rule, as in Jetstream/Fortify password updates),
        // so an unattended session cannot take over the account.
        $user = $authoring->fromPlaintext((int) $user->getKey(), $request->validated());
        auth('web')->setUser($user);
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
    public function testBunnyConnection(BunnyConnectionTestRequest $request, AdminBunnyConnectionService $connection)
    {
        $result = $connection->test($request->validated());

        return response()->json($result, ($result['code'] ?? null) === 'credentials_missing' ? 422 : 200);
    }
}
