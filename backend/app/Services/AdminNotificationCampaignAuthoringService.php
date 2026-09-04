<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Support\PublicDiskUrl;
use App\Support\RoknAppLink;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AdminNotificationCampaignAuthoringService
{
    public function __construct(
        private CoursePublishingService $publishing,
        private AdminAuthoringCreateIntentService $createIntents,
        private StoredFileDeletionService $files,
    ) {
    }

    /** @param array<string, mixed> $validated */
    public function author(Request $request, array $validated): NotificationCampaign
    {
        $targetStudent = !empty($validated['user_id'])
            ? User::query()->students()->findOrFail((int) $validated['user_id'])
            : null;
        if ($targetStudent && (!(bool) $targetStudent->active || $targetStudent->trashed())) {
            throw ValidationException::withMessages([
                'user_id' => ['فعّل حساب الطالب قبل إرسال الإشعار'],
            ]);
        }

        $titleAr = trim((string) $validated['title_ar']);
        $messageAr = trim((string) $validated['message_ar']);
        $titleEn = trim((string) ($validated['title_en'] ?? '')) ?: $titleAr;
        $messageEn = trim((string) ($validated['message_en'] ?? '')) ?: $messageAr;
        $courseId = !empty($validated['course_id']) ? (int) $validated['course_id'] : null;
        $audience = $targetStudent ? 'all' : (string) ($validated['audience'] ?? 'all');
        $notificationType = $targetStudent
            ? 'admin_message'
            : (!$courseId
                ? (($validated['notification_kind'] ?? null) === 'service'
                    ? 'service_notice'
                    : 'admin_broadcast')
                : ($audience === 'enrolled' ? 'continue_course' : 'course_promotion'));
        $actionLabelAr = trim((string) ($validated['action_label'] ?? '')) ?: ($courseId
            ? ($audience === 'enrolled' ? 'أكمل من مكانك' : 'تفاصيل الكورس')
            : 'افتح ركن');
        $link = $courseId
            ? RoknAppLink::course($courseId, !$targetStudent && $audience === 'enrolled')
            : (RoknAppLink::normalize($validated['action_link'] ?? null) ?: 'rokn://home');
        $scheduledAt = $this->scheduledAt($validated['send_at'] ?? null);
        $authorId = (int) ($request->user()?->getAuthIdentifier() ?? 0);
        abort_if($authorId <= 0, 403);

        if ($courseId) {
            $this->assertCourseCanReceive($courseId, $audience, $targetStudent !== null);
        }

        $requestId = strtolower((string) $validated['authoring_request_id']);
        $deliveryKey = ($targetStudent
            ? 'admin-message:' . $authorId . ':' . $targetStudent->id . ':'
            : 'admin-broadcast:' . $authorId . ':') . $requestId;
        if (strlen($deliveryKey) > 64) {
            $deliveryKey = hash('sha256', $deliveryKey);
        }
        $userIds = $targetStudent ? [(int) $targetStudent->id] : [];
        $imageIdentity = ($targetStudent ? 'notification-user|' : 'notification-campaign|') . $deliveryKey;

        $existing = NotificationCampaign::query()->where('delivery_key', $deliveryKey)->first();
        if ($existing) {
            $this->assertReplayMatches(
                $existing,
                $request,
                compact(
                    'notificationType', 'audience', 'courseId', 'titleAr', 'titleEn',
                    'messageAr', 'messageEn', 'actionLabelAr', 'link', 'scheduledAt',
                    'userIds', 'imageIdentity'
                )
            );
            $this->completeIntent($request, $existing);

            return $existing;
        }

        $imagePath = null;
        $imageUrl = null;
        if ($request->hasFile('image')) {
            $image = $request->file('image');
            $imagePath = $this->files->storeTrackedUpload(
                $image,
                'student-notifications',
                'public',
                60,
                $imageIdentity . '|' . hash_file('sha256', $image->getRealPath())
            );
            if (!is_string($imagePath) || trim($imagePath) === '') {
                throw ValidationException::withMessages(['image' => ['تعذّر حفظ الصورة']]);
            }
            $imageUrl = PublicDiskUrl::from($imagePath);
        }

        $campaign = null;
        $committed = false;
        try {
            DB::transaction(function () use (
                $request,
                $notificationType,
                $audience,
                $courseId,
                $titleAr,
                $titleEn,
                $messageAr,
                $messageEn,
                $actionLabelAr,
                $link,
                $scheduledAt,
                $deliveryKey,
                $userIds,
                $imageUrl,
                $authorId,
                &$campaign
            ): void {
                $queued = NotificationService::notifyGeneric($notificationType, $userIds, [
                    'title_ar' => $titleAr,
                    'title_en' => $titleEn,
                    'message_ar' => $messageAr,
                    'message_en' => $messageEn,
                    'link' => $link,
                    'notifiable_type' => $courseId ? Course::class : null,
                    'notifiable_id' => $courseId,
                    'course_id' => $courseId,
                    'audience' => $audience,
                    'delivery_key' => $deliveryKey,
                    'image_url' => $imageUrl,
                    'action_label_ar' => $actionLabelAr,
                    'action_label_en' => $courseId
                        ? ($audience === 'enrolled' ? 'Continue learning' : 'View course')
                        : 'Open Rokn',
                    'scheduled_at' => $scheduledAt,
                    'authored_by' => $authorId,
                ]);
                $campaign = NotificationCampaign::query()
                    ->where('delivery_key', $deliveryKey)
                    ->first();
                if (!$queued && !$campaign) {
                    throw ValidationException::withMessages([
                        'notification_kind' => ['هذا النوع متوقف حاليًا من إعدادات الإشعارات'],
                    ]);
                }
                $this->completeIntent($request, $campaign);
            }, 3);
            $committed = true;
        } finally {
            if (!$committed && is_string($imagePath) && $imagePath !== '') {
                $this->files->deleteOrQueue('public', $imagePath);
            }
        }

        return $campaign ?? NotificationCampaign::query()
            ->where('delivery_key', $deliveryKey)
            ->firstOrFail();
    }

    private function scheduledAt(mixed $value): ?Carbon
    {
        if (trim((string) $value) === '') {
            return null;
        }
        $scheduledAt = Carbon::createFromFormat('Y-m-d\TH:i', (string) $value, 'Africa/Cairo')->utc();
        if ($scheduledAt->isAfter(now()->addDays(90))) {
            throw ValidationException::withMessages(['send_at' => ['اختر موعدًا خلال ٩٠ يومًا']]);
        }

        return $scheduledAt;
    }

    private function assertCourseCanReceive(int $courseId, string $audience, bool $individual): void
    {
        $course = Course::query()->findOrFail($courseId);
        $audit = $this->publishing->audit($course);
        if ($course->is_coming_soon || !($audit['ready'] ?? false)) {
            throw ValidationException::withMessages([
                'course_id' => ['لا يمكن إرسال الطالب إلى كورس غير جاهز للنشر'],
            ]);
        }
        if (!$individual && $audience !== 'enrolled' && !$course->is_catalog_visible) {
            throw ValidationException::withMessages([
                'course_id' => ['هذا الكورس مخفي من الكتالوج ولا يصلح لإشعار ترويجي'],
            ]);
        }
    }

    /** @param array<string, mixed> $expected */
    private function assertReplayMatches(
        NotificationCampaign $existing,
        Request $request,
        array $expected
    ): void {
        $scheduledAt = $expected['scheduledAt'];
        // An immediate marketing request can be moved by the delivery policy
        // to the end of quiet hours. That derived time is not authored form
        // data, so a replay of the same intent must not recalculate it later.
        $sameSchedule = true;
        if ($scheduledAt) {
            $effectiveSchedule = NotificationDeliveryPolicy::nextAllowedAt(
                $expected['notificationType'],
                $scheduledAt
            );
            if (!$effectiveSchedule->isAfter(now()->addSeconds(30))) {
                $effectiveSchedule = null;
            }
            $sameSchedule = $effectiveSchedule === null
                ? $existing->scheduled_at === null
                : $existing->scheduled_at?->equalTo($effectiveSchedule) === true;
        }
        $sameUsers = array_values(array_map('intval', (array) $existing->user_ids))
            === array_values(array_map('intval', $expected['userIds']));
        $samePayload = hash_equals((string) $existing->notification_type, $expected['notificationType'])
            && hash_equals((string) $existing->audience, $expected['audience'])
            && (int) ($existing->course_id ?: 0) === (int) ($expected['courseId'] ?: 0)
            && hash_equals((string) $existing->title_ar, $expected['titleAr'])
            && hash_equals((string) $existing->title_en, $expected['titleEn'])
            && hash_equals((string) $existing->message_ar, $expected['messageAr'])
            && hash_equals((string) $existing->message_en, $expected['messageEn'])
            && hash_equals((string) $existing->action_label_ar, $expected['actionLabelAr'])
            && hash_equals((string) ($existing->link ?: ''), (string) ($expected['link'] ?: ''))
            && $sameSchedule
            && $sameUsers;
        $replayHasImage = $request->hasFile('image');
        $samePayload = $samePayload
            && (!$replayHasImage || $this->notificationImageMatches(
                (string) $existing->image_url,
                $request->file('image'),
                $expected['imageIdentity']
            ));
        if (!$samePayload) {
            throw ValidationException::withMessages([
                'authoring_request_id' => ['تغيّرت بيانات الإشعار\nأعد فتح النموذج ثم أرسل'],
            ]);
        }
    }

    private function notificationImageMatches(string $url, UploadedFile $image, string $identityPrefix): bool
    {
        $storedIdentity = pathinfo((string) (parse_url($url, PHP_URL_PATH) ?: ''), PATHINFO_FILENAME);

        return $storedIdentity !== '' && hash_equals(
            $storedIdentity,
            hash('sha256', $identityPrefix . '|' . hash_file('sha256', $image->getRealPath()))
        );
    }

    private function completeIntent(Request $request, ?NotificationCampaign $campaign): void
    {
        $this->createIntents->completeRedirect(
            $request,
            route('admin.notifications.index'),
            302,
            $campaign ? NotificationCampaign::class : null,
            $campaign?->id
        );
    }
}
