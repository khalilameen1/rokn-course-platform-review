<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

final class SupportCaseService
{
    public const CUSTOMER_STATUSES = ['new', 'reviewing', 'waiting_for_user', 'resolved', 'closed', 'dismissed'];

    public function createGuestCredential(string $clientRequestId): array
    {
        $secret = (string) config('app.key');
        abort_if($secret === '', 503, 'تعذّر فتح المتابعة الآن');
        $bytes = hash_hmac('sha256', 'support-case|'.$clientRequestId, $secret, true);
        $token = rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }

    public function authorizeViewer(FeedbackReport $report, ?User $user, ?string $accessToken): void
    {
        if ($user && (int) $report->user_id === (int) $user->id) {
            return;
        }
        $digest = trim((string) $report->guest_access_hash);
        $candidate = trim((string) $accessToken);
        if ($digest !== '' && $candidate !== '' && hash_equals($digest, hash('sha256', $candidate))) {
            return;
        }
        abort(404);
    }

    public function accessTokenFromRequest(\Illuminate\Http\Request $request): ?string
    {
        $token = trim((string) $request->header('X-Support-Access'));
        return $token !== '' && strlen($token) <= 128 ? $token : null;
    }

    public function appendLearnerMessage(
        FeedbackReport $report,
        ?User $user,
        string $body,
        string $clientRequestId,
        ?UploadedFile $screenshot = null
    ): SupportCaseMessage {
        $body = trim($body);
        $fingerprint = hash('sha256', json_encode([
            'body' => $body,
            'attachment' => $screenshot ? $this->uploadFingerprint($screenshot) : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = SupportCaseMessage::query()
            ->where('feedback_report_id', $report->id)
            ->where('client_request_id', $clientRequestId)
            ->first();
        if ($existing) {
            abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
            return $existing;
        }

        $stagedAttachment = $screenshot
            ? $this->stageSanitizedImage($report, $clientRequestId, $screenshot)
            : null;

        return DB::transaction(function () use (
                $report,
                $user,
                $body,
                $clientRequestId,
                $fingerprint,
                $stagedAttachment
            ): SupportCaseMessage {
                if ($user) {
                    User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                }
                $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
                $existing = SupportCaseMessage::query()
                    ->where('feedback_report_id', $locked->id)
                    ->where('client_request_id', $clientRequestId)
                    ->first();
                if ($existing) {
                    abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
                    return $existing;
                }

                $fromStatus = (string) $locked->status;
                $reopened = in_array($fromStatus, ['resolved', 'closed', 'dismissed'], true);
                $message = SupportCaseMessage::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'feedback_report_id' => $locked->id,
                    'author_id' => $user?->id,
                    'author_type' => SupportCaseMessage::AUTHOR_LEARNER,
                    'visibility' => SupportCaseMessage::VISIBILITY_CUSTOMER,
                    'body' => $body,
                    'client_request_id' => $clientRequestId,
                    'request_fingerprint' => $fingerprint,
                ]);

                if ($stagedAttachment) {
                    $this->attachStagedImage($locked, $message, $stagedAttachment);
                }

                $updates = [
                    'last_user_message_at' => now(),
                    'version' => (int) $locked->version + 1,
                    'updated_at' => now(),
                ];
                if ($reopened) {
                    $updates += [
                        'status' => 'reviewing',
                        'resolved_at' => null,
                        'closed_at' => null,
                        'reopened_at' => now(),
                    ];
                }
                $locked->update($updates);
                $this->event($locked, $user?->id, $reopened ? 'reopened' : 'learner_replied', $fromStatus, $updates['status'] ?? $fromStatus);

                return $message;
            }, 3);
    }

    public function appendStaffMessage(
        FeedbackReport $report,
        User $staff,
        string $body,
        string $visibility,
        string $clientRequestId,
        int $expectedVersion
    ): SupportCaseMessage {
        $body = trim($body);
        $visibility = $visibility === SupportCaseMessage::VISIBILITY_INTERNAL
            ? SupportCaseMessage::VISIBILITY_INTERNAL
            : SupportCaseMessage::VISIBILITY_CUSTOMER;
        $fingerprint = hash('sha256', $visibility.'|'.$body);

        $message = DB::transaction(function () use (
            $report,
            $staff,
            $body,
            $visibility,
            $clientRequestId,
            $expectedVersion,
            $fingerprint
        ): SupportCaseMessage {
            if ($report->user_id) {
                User::withTrashed()->whereKey($report->user_id)->lockForUpdate()->first();
            }
            $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
            $existing = SupportCaseMessage::query()
                ->where('feedback_report_id', $locked->id)
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
                if ($visibility === SupportCaseMessage::VISIBILITY_CUSTOMER && $locked->user_id) {
                    $this->notifyCustomer($locked, $existing);
                }
                return $existing;
            }
            abort_if((int) $locked->version !== $expectedVersion, 409, "عدّل شخص آخر هذه الحالة\nحدّث الصفحة ثم أعد المحاولة");

            $message = SupportCaseMessage::query()->create([
                'public_id' => (string) Str::ulid(),
                'feedback_report_id' => $locked->id,
                'author_id' => $staff->id,
                'author_type' => SupportCaseMessage::AUTHOR_STAFF,
                'visibility' => $visibility,
                'body' => $body,
                'client_request_id' => $clientRequestId,
                'request_fingerprint' => $fingerprint,
            ]);
            $updates = ['version' => (int) $locked->version + 1, 'updated_at' => now()];
            if ($visibility === SupportCaseMessage::VISIBILITY_CUSTOMER) {
                $updates['last_staff_message_at'] = now();
                if ($locked->status === 'new') $updates['status'] = 'reviewing';
            }
            $locked->update($updates);
            $this->event($locked, $staff->id, $visibility === 'internal' ? 'internal_note' : 'staff_replied');
            if ($visibility === SupportCaseMessage::VISIBILITY_CUSTOMER && $locked->user_id) {
                $this->notifyCustomer($locked, $message);
            }

            return $message;
        }, 3);
        return $message;
    }

    public function customerPayload(FeedbackReport $report): array
    {
        $report->load([
            'course:id,name_ar,name_en',
            'attachments' => fn ($query) => $query
                ->whereNull('support_case_message_id')
                ->where('scan_status', 'sanitized')
                ->orderBy('id'),
            'messages' => fn ($query) => $query
            ->where('visibility', SupportCaseMessage::VISIBILITY_CUSTOMER)
            ->with(['attachments' => fn ($attachments) => $attachments
                ->where('scan_status', 'sanitized')
                ->orderBy('id')])
            ->orderBy('id'),
        ]);

        return [
            'public_id' => $report->public_id,
            'case_number' => strtoupper(substr((string) $report->public_id, -8)),
            'category' => $report->category,
            'status' => $this->customerStatus((string) $report->status),
            'message' => $report->message,
            'course' => $report->course ? ['id' => (int) $report->course->id, 'title' => $report->course->title] : null,
            'created_at' => $report->created_at?->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
            'attachments' => $report->attachments
                ->map(fn (FeedbackAttachment $attachment): array => $this->customerAttachment(
                    $report,
                    $attachment
                ))->values()->all(),
            'messages' => $report->messages->map(fn (SupportCaseMessage $message): array => [
                'public_id' => $message->public_id,
                'author' => $message->author_type === SupportCaseMessage::AUTHOR_LEARNER ? 'learner' : 'support',
                'text' => $message->body,
                'has_attachment' => $message->attachments->isNotEmpty(),
                'attachments' => $message->attachments
                    ->map(fn (FeedbackAttachment $attachment): array => $this->customerAttachment(
                        $report,
                        $attachment
                    ))->values()->all(),
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** @return array{id:string,name:string,mime:string,size:int,width:?int,height:?int,url:string,expires_at:string} */
    private function customerAttachment(
        FeedbackReport $report,
        FeedbackAttachment $attachment
    ): array {
        $expiresAt = now()->addMinutes(15);
        return [
            'id' => (string) $attachment->id,
            'name' => 'support-' . strtoupper(substr((string) $report->public_id, -8))
                . '-' . $attachment->id . '.jpg',
            'mime' => (string) ($attachment->mime_type ?: 'image/jpeg'),
            'size' => max(0, (int) $attachment->size_bytes),
            'width' => $attachment->width ? (int) $attachment->width : null,
            'height' => $attachment->height ? (int) $attachment->height : null,
            'url' => URL::temporarySignedRoute(
                'api.feedback.attachment',
                $expiresAt,
                ['publicId' => $report->public_id, 'attachment' => $attachment->id]
            ),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function firstResponseDueAt(string $priority = 'normal'): \Carbon\CarbonInterface
    {
        $hours = ['urgent' => 2, 'high' => 8, 'normal' => 24, 'low' => 72][$priority] ?? 24;
        return now()->addHours($hours);
    }

    public function notifyStatus(FeedbackReport $report, string $status, string $deliveryKey): void
    {
        $user = $report->user;
        if (!$user) return;
        [$titleAr, $messageAr] = match ($status) {
            'resolved' => ['تم حل البلاغ', 'راجع رد فريق الدعم على البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
            'waiting_for_user' => ['ينتظر الدعم ردك', 'أرسل التفاصيل المطلوبة في البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
            default => ['تحديث على بلاغك', 'راجع آخر تحديث على البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
        };
        StudentNotificationService::notifyUser(
            $user,
            StudentNotificationService::TYPE_SUPPORT_CASE_UPDATE,
            $titleAr,
            'Support case updated',
            $messageAr,
            'Your support case was updated',
            'rokn://support/'.$report->public_id,
            FeedbackReport::class,
            (int) $report->id,
            $deliveryKey,
            ['case' => strtoupper(substr((string) $report->public_id, -8))]
        );
    }

    public function event(
        FeedbackReport $report,
        ?int $actorId,
        string $type,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        array $metadata = []
    ): SupportCaseEvent {
        return SupportCaseEvent::query()->create([
            'feedback_report_id' => $report->id,
            'actor_id' => $actorId,
            'event_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $this->safeEventMetadata($metadata),
        ]);
    }

    /** @return array{path:string,mime_type:string,size_bytes:int,width:int,height:int,sha256:string} */
    private function stageSanitizedImage(
        FeedbackReport $report,
        string $clientRequestId,
        UploadedFile $upload
    ): array {
        try {
            $image = Image::make($upload->getRealPath());
        } catch (\Throwable) {
            abort(422, "تعذّرت قراءة الصورة\nاختر صورة أخرى");
        }
        if (function_exists('exif_read_data')) $image->orientate();
        $image->resize(2048, 2048, static function ($constraint): void {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $encoded = (string) $image->encode('jpg', 86);
        abort_if($encoded === '', 422, "تعذّرت قراءة الصورة\nاختر صورة أخرى");
        $sha = hash('sha256', $encoded);
        $directory = ($report->created_at ?: now())->format('Y/m');
        $path = $directory.'/'.$report->public_id.'/'.hash(
            'sha256',
            'support-message|'.$report->public_id.'|'.strtolower($clientRequestId).'|'.$sha
        ).'.jpg';
        app(StoredFileDeletionService::class)
            ->trackPotentialOrphan('feedback', $path, 60);
        abort_unless(Storage::disk('feedback')->put($path, $encoded), 503, 'تعذّر حفظ الصورة الآن');

        return [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size_bytes' => strlen($encoded),
            'width' => $image->width(),
            'height' => $image->height(),
            'sha256' => $sha,
        ];
    }

    /** @param array{path:string,mime_type:string,size_bytes:int,width:int,height:int,sha256:string} $staged */
    private function attachStagedImage(
        FeedbackReport $report,
        SupportCaseMessage $message,
        array $staged
    ): FeedbackAttachment {
        return $report->attachments()->firstOrCreate([
            'support_case_message_id' => $message->id,
            'path' => $staged['path'],
        ], [
            'disk' => 'feedback',
            'mime_type' => $staged['mime_type'],
            'size_bytes' => $staged['size_bytes'],
            'width' => $staged['width'],
            'height' => $staged['height'],
            'sha256' => $staged['sha256'],
            'scan_status' => 'sanitized',
        ]);
    }

    private function uploadFingerprint(UploadedFile $file): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        abort_unless($hash && $file->getSize() > 0, 422, "تعذّرت قراءة الصورة\nاختر صورة أخرى");
        return ['sha256' => $hash, 'size' => (int) $file->getSize()];
    }

    private function notifyCustomer(FeedbackReport $report, SupportCaseMessage $message): void
    {
        $user = $report->user;
        if (!$user) return;
        StudentNotificationService::notifyUser(
            $user,
            StudentNotificationService::TYPE_SUPPORT_CASE_UPDATE,
            'رد فريق الدعم',
            'Support replied',
            'لديك رد جديد على البلاغ '.strtoupper(substr((string) $report->public_id, -8)),
            'You have a new support reply',
            'rokn://support/'.$report->public_id,
            FeedbackReport::class,
            (int) $report->id,
            'support-case:'.$report->id.':message:'.$message->id,
            ['case' => strtoupper(substr((string) $report->public_id, -8))]
        );
    }

    private function customerStatus(string $status): string
    {
        return match ($status) {
            'new' => 'received',
            'reviewing' => 'in_progress',
            'waiting_for_user' => 'waiting_for_you',
            'resolved' => 'resolved',
            'closed', 'dismissed' => 'closed',
            default => 'in_progress',
        };
    }

    private function safeEventMetadata(array $metadata): array
    {
        return array_intersect_key($metadata, array_flip([
            'assigned_to', 'priority', 'resolution_kind', 'order_id', 'compensation_event_key',
        ]));
    }
}
