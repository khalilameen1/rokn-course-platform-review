<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotificationCampaign;
use App\Services\AdminNotificationCampaignAuthoringService;
use App\Services\AdminNotificationCampaignReadService;
use App\Services\NotificationCampaignService;
use App\Support\RoknAppLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class NotificationsController extends Controller
{
    public function index(AdminNotificationCampaignReadService $notifications): View
    {
        return view('admin.notifications.index', [
            'campaigns' => $notifications->campaigns(),
        ]);
    }

    public function create(Request $request, AdminNotificationCampaignReadService $notifications): View
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'course_search' => ['nullable', 'string', 'max:100'],
        ]);

        return view(
            'admin.notifications.create',
            $notifications->authoringContext(
                isset($validated['user_id']) ? (int) $validated['user_id'] : null,
                $validated['course_search'] ?? null
            )
        );
    }

    public function store(
        Request $request,
        ?AdminNotificationCampaignAuthoringService $notifications = null
    ): RedirectResponse {
        $notifications ??= app(AdminNotificationCampaignAuthoringService::class);
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'title_ar' => ['required', 'string', 'max:80'],
            'message_ar' => ['required', 'string', 'max:240'],
            'title_en' => ['nullable', 'string', 'max:80'],
            'message_en' => ['nullable', 'string', 'max:240'],
            'course_id' => ['nullable', 'required_unless:audience,all', 'integer', 'exists:courses,id'],
            'audience' => ['required', 'string', 'in:all,not_enrolled,enrolled'],
            'notification_kind' => ['nullable', 'string', 'in:marketing,service'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:4096'],
            'action_label' => ['nullable', 'string', 'max:40'],
            'action_link' => [
                'nullable',
                'string',
                'max:2000',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (trim((string) $value) !== '' && RoknAppLink::normalize($value) === null) {
                        $fail('اختر وجهة صحيحة داخل ركن');
                    }
                },
            ],
            'authoring_request_id' => ['required', 'uuid'],
            'send_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
        ]);

        $campaign = $notifications->author($request, $validated);

        return redirect()->route('admin.notifications.index')->with(
            'success',
            $campaign->status === NotificationCampaign::STATUS_SCHEDULED
                ? 'تم حفظ الإشعار في موعده'
                : 'تمت إضافة الإشعار إلى قائمة الإرسال'
        );
    }

    public function retry(
        NotificationCampaign $notificationCampaign,
        NotificationCampaignService $campaigns
    ): RedirectResponse {
        if (!$campaigns->retry($notificationCampaign)) {
            return redirect()
                ->route('admin.notifications.index')
                ->with('warning', 'هذه الحملة ليست متوقفة الآن');
        }

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', 'أُعيدت الحملة إلى قائمة الإرسال');
    }
}
