<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Data\NotificationAuthoringInput;
use App\Services\AdminAuthoringCreateIntentService;
use App\Models\NotificationCampaign;
use App\Services\AdminNotificationCampaignAuthoringService;
use App\Services\AdminNotificationCampaignReadService;
use App\Models\User;
use App\Services\NotificationCampaignService;
use App\Support\RoknAppLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        AdminNotificationCampaignAuthoringService $notifications,
        AdminAuthoringCreateIntentService $createIntents
    ): RedirectResponse {
        $validated = $request->validate($this->campaignRules() + [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'authoring_request_id' => ['required', 'uuid'],
            // Broadcasts are released only after staff saw the recipient
            // count and confirmed it (Mailchimp/Klaviyo pre-send review).
            'confirm_send' => ['exclude_with:user_id', 'accepted'],
        ], [
            'confirm_send.accepted' => 'راجع عدد المستلمين وأكّد الإرسال',
        ]);
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
        Request $request,
        AdminNotificationCampaignAuthoringService $notifications
    ): JsonResponse {
        $validated = $request->validate([
            'audience' => ['required', 'string', 'in:all,not_enrolled,enrolled'],
            'course_id' => ['nullable', 'required_unless:audience,all', 'integer', 'exists:courses,id'],
            'notification_kind' => ['nullable', 'string', 'in:marketing,service'],
        ]);
        $courseId = !empty($validated['course_id']) ? (int) $validated['course_id'] : null;

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
        Request $request,
        AdminNotificationCampaignAuthoringService $notifications
    ): JsonResponse|RedirectResponse {
        $validated = $request->validate($this->campaignRules() + [
            'test_email' => ['required', 'email:rfc', 'max:255'],
        ], [
            'test_email.required' => 'اكتب بريد حساب الطالب الذي سيستلم التجربة',
        ]);
        $student = User::query()->students()
            ->where('email', strtolower(trim($validated['test_email'])))
            ->first();
        if (!$student) {
            throw ValidationException::withMessages([
                'test_email' => ['لا يوجد حساب طالب بهذا البريد'],
            ]);
        }
        unset($validated['test_email']);
        $validated['user_id'] = (int) $student->id;
        $validated['send_at'] = null;
        $validated['authoring_request_id'] = (string) Str::uuid();
        $validated['title_ar'] = Str::limit('تجربة: '.trim($validated['title_ar']), 80, '');
        if (!empty($validated['title_en'])) {
            $validated['title_en'] = Str::limit('[Test] '.trim($validated['title_en']), 80, '');
        }

        $notifications->author(
            NotificationAuthoringInput::fromValidated((int) ($request->user()?->getAuthIdentifier() ?? 0), $validated),
            static function (?NotificationCampaign $campaign): void {
            }
        );
        $message = 'أُرسلت نسخة تجريبية إلى '.$student->email;

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
        if (!$campaigns->cancel($notificationCampaign)) {
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
        if (!$campaigns->retry($notificationCampaign)) {
            return redirect()
                ->route('admin.notifications.index')
                ->with('warning', 'هذه الحملة ليست متوقفة الآن');
        }

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', 'أُعيدت الحملة إلى قائمة الإرسال');
    }

    /** @return array<string, mixed> */
    private function campaignRules(): array
    {
        return [
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
            'send_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
        ];
    }
}
