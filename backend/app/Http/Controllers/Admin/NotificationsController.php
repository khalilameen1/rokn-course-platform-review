<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Data\NotificationAuthoringInput;
use App\Filament\Resources\NotificationCampaignResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NotificationAudienceRequest;
use App\Http\Requests\Admin\NotificationCampaignRequest;
use App\Http\Requests\Admin\NotificationCreateContextRequest;
use App\Models\NotificationCampaign;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminNotificationCampaignAuthoringService;
use App\Services\NotificationCampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

final class NotificationsController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->to(NotificationCampaignResource::getUrl());
    }

    public function create(NotificationCreateContextRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        return redirect()->to(isset($validated['user_id'])
            ? NotificationCampaignResource::getUrl('create-for-student', ['student' => $validated['user_id']])
            : NotificationCampaignResource::getUrl('create'));
    }

    public function store(
        NotificationCampaignRequest $request,
        AdminNotificationCampaignAuthoringService $notifications,
        AdminAuthoringCreateIntentService $createIntents
    ): RedirectResponse {
        $validated = $request->validated();
        unset($validated['confirm_send']);

        $campaign = $notifications->author(
            NotificationAuthoringInput::fromValidated((int) ($request->user()?->getAuthIdentifier() ?? 0), $validated),
            static function (?NotificationCampaign $campaign) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request,
                    route('admin.notifications.index'),
                    302,
                    $campaign ? NotificationCampaign::class : null,
                    $campaign?->id
                );
            }
        );

        return redirect()->route('admin.notifications.index')->with(
            'success',
            $campaign->status === NotificationCampaign::STATUS_SCHEDULED
                ? 'تم حفظ الإشعار في موعده'
                : 'تمت إضافة الإشعار إلى قائمة الإرسال'
        );
    }

    /**
     * Pre-send recipient count for the selected audience (Mailchimp/Klaviyo
     * show the list size on the review step before "Send"). Uses the same
     * selector as delivery, evaluated now.
     */
    public function audiencePreview(
        NotificationAudienceRequest $request,
        AdminNotificationCampaignAuthoringService $notifications
    ): JsonResponse {
        $validated = $request->validated();
        $courseId = ! empty($validated['course_id']) ? (int) $validated['course_id'] : null;

        return response()->json([
            'recipients' => $notifications->previewRecipientCount(
                $courseId,
                $validated['audience'],
                $validated['notification_kind'] ?? null
            ),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Send the current draft once to a single learner account chosen by
     * email, as an individual message marked as a test (the "send test"
     * step of Mailchimp/Klaviyo). It never touches the broadcast audience
     * and is listed in the campaign log like any individual message.
     */
    public function test(
        NotificationCampaignRequest $request,
        AdminNotificationCampaignAuthoringService $notifications
    ): JsonResponse|RedirectResponse {
        $validated = $request->validated();
        $notifications->authorTest((int) $request->user()->getAuthIdentifier(), $validated, $validated['test_email']);
        $message = 'أُضيفت التجربة إلى قائمة الإرسال';

        return $request->expectsJson()
            ? response()->json(['message' => $message])
            : back()->withInput($request->except('image'))->with('success', $message);
    }

    /**
     * Withdraw a scheduled or not-yet-started campaign (Mailchimp's
     * "Unschedule"). The transition is a single compare-and-set, so a
     * campaign that already started delivering cannot be half-cancelled.
     */
    public function cancel(
        NotificationCampaign $notificationCampaign,
        NotificationCampaignService $campaigns
    ): RedirectResponse {
        if (! $campaigns->cancel($notificationCampaign)) {
            return redirect()
                ->route('admin.notifications.index')
                ->with('warning', "بدأ إرسال هذه الحملة أو انتهى\nلا يمكن إلغاؤها الآن");
        }

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', 'أُلغيت الحملة ولن تُرسل');
    }

    public function retry(
        NotificationCampaign $notificationCampaign,
        NotificationCampaignService $campaigns
    ): RedirectResponse {
        if (! $campaigns->retry($notificationCampaign)) {
            return redirect()
                ->route('admin.notifications.index')
                ->with('warning', 'هذه الحملة ليست متوقفة الآن');
        }

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', 'أُعيدت الحملة إلى قائمة الإرسال');
    }
}
