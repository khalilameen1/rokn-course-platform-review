<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\BunnyDirectUpload;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use App\Support\DatabaseCapabilities;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class BunnyDirectUploadService
{
    public const MAX_BYTES = 5 * 1024 * 1024 * 1024;
    public const MIMES = [
        'video/mp4',
        'video/quicktime',
        'video/x-msvideo',
        'video/webm',
    ];

    public function __construct(private BunnyService $bunny)
    {
    }

    /** @return array<string, mixed> */
    public function issue(
        Course $course,
        User $admin,
        string $title,
        int $size,
        string $mime,
        string $originalName,
        string $idempotencyKey,
        ?CourseSection $section,
        int $expectedAuthoringVersion
    ): array {
        $this->assertAuthoringContext($course, $admin, $section);
        $this->assertExpectedAuthoringVersion($course, $expectedAuthoringVersion);
        $title = trim($title);
        $mime = strtolower(trim($mime));
        $originalName = basename(str_replace('\\', '/', trim($originalName)));
        if ($title === '' || mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['title' => 'أضف عنوان المقطع أولًا']);
        }
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw ValidationException::withMessages(['size' => 'حجم الفيديو يجب ألا يتجاوز 5GB']);
        }
        if (!in_array($mime, self::MIMES, true)) {
            throw ValidationException::withMessages(['mime' => 'صيغة الفيديو غير مدعومة']);
        }
        $expectedExtension = match ($mime) {
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            'video/webm' => 'webm',
        };
        if (strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) !== $expectedExtension) {
            throw ValidationException::withMessages([
                'original_name' => 'صيغة الملف لا تطابق محتواه المعلن',
            ]);
        }
        if (preg_match('/^[a-f0-9-]{36}$/i', $idempotencyKey) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'أعد اختيار ملف الفيديو']);
        }

        $requestIdentity = [
            'course' => (int) $course->id,
            'section' => $section ? (int) $section->id : null,
            'title' => $title,
            'size' => $size,
            'mime' => $mime,
            'original_name' => $originalName,
        ];
        $requestIdentity['authoring_version'] = $expectedAuthoringVersion;
        $requestHash = hash('sha256', json_encode($requestIdentity, JSON_THROW_ON_ERROR));
        $lockName = sprintf('bunny-direct-upload:%d:%d:%s', $admin->id, $course->id, $idempotencyKey);

        return Cache::lock($lockName, 180)->block(10, function () use (
            $course,
            $admin,
            $section,
            $title,
            $size,
            $mime,
            $idempotencyKey,
            $expectedAuthoringVersion,
            $requestHash
        ): array {
            // The cache lock may wait behind a duplicate request. Recheck the
            // page revision after acquiring it so that wait cannot turn an
            // already-stale tab into a new remote allocation.
            $this->assertExpectedAuthoringVersion($course, $expectedAuthoringVersion);
            $session = BunnyDirectUpload::query()
                ->where('user_id', $admin->id)
                ->where('course_id', $course->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($session) {
                $sameRequest = hash_equals((string) $session->request_hash, $requestHash);
                if (!$sameRequest) {
                    throw $this->terminalOperation("تغيرت بيانات الملف\nاختره مرة أخرى");
                }
                if ($session->status === 'pending'
                    && $session->expires_at->isFuture()
                    && $this->validGuid((string) $session->video_guid)) {
                    return $this->payload(
                        $session,
                        $course,
                        $admin,
                        $title,
                        $size,
                        $mime,
                        $section,
                        $expectedAuthoringVersion
                    );
                }
                if ($session->expires_at->isPast()) {
                    $session->forceFill(['status' => 'failed'])->save();
                    throw $this->terminalOperation("انتهت عملية الرفع\nاختر الملف مرة أخرى");
                }

                if ($session->status !== 'allocating') {
                    throw $this->terminalOperation("انتهت عملية الرفع\nاختر الملف مرة أخرى");
                }

                $allocationLease = max(60, (int) config('bunny.direct_upload_allocation_lease_seconds', 120));
                if ($session->updated_at && $session->updated_at->gt(now()->subSeconds($allocationLease))) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => "ما زال تجهيز الرفع جاريًا\nحاول بعد لحظات",
                        'bunny_upload_allocation_in_progress' => 'allocation_in_progress',
                    ])->status(409);
                }

                $this->retireStaleAllocation($session, $admin);
            }

            $expiresAt = now()->addHours(max(2, (int) config('bunny.direct_upload_claim_ttl_hours', 24)));
            $allocationToken = (string) Str::uuid();
            if ($session) {
                $session->forceFill([
                    'video_guid' => null,
                    'allocation_token' => $allocationToken,
                    'status' => 'allocating',
                    'expires_at' => $expiresAt,
                    'attached_at' => null,
                ])->save();
            } else {
                $session = BunnyDirectUpload::query()->create([
                    'user_id' => $admin->id,
                    'course_id' => $course->id,
                    'section_id' => $section?->id,
                    'idempotency_key' => strtolower($idempotencyKey),
                    'request_hash' => $requestHash,
                    'allocation_token' => $allocationToken,
                    'status' => 'allocating',
                    'expires_at' => $expiresAt,
                ]);
            }

            $videoId = null;
            try {
                $remoteTitle = mb_substr($title, 0, 205) . ' [rokn:' . strtolower($idempotencyKey) . ']';
                $video = $this->bunny->createVideo($remoteTitle);
                $videoId = strtolower(trim((string) ($video['guid'] ?? '')));
                if (!$this->validGuid($videoId)) {
                    throw new RuntimeException('تعذر تجهيز مساحة رفع الفيديو');
                }
                $claimed = BunnyDirectUpload::query()
                    ->whereKey($session->id)
                    ->where('status', 'allocating')
                    ->where('allocation_token', $allocationToken)
                    ->update(['video_guid' => $videoId, 'updated_at' => now()]);
                if ($claimed !== 1) {
                    $this->queueAbandonedAllocation($videoId, $admin, 'direct_upload_superseded_allocation');
                    throw $this->terminalOperation("انتهت محاولة تجهيز الرفع\nحاول مرة أخرى");
                }
                $session->refresh();
                $candidate = $this->bunny->queueVideoCleanup(
                    $videoId,
                    $section?->sectionable instanceof Lesson ? $section->sectionable : null,
                    'direct_upload_pending',
                    24
                );
                if (!$candidate) {
                    $this->bunny->deleteVideo($videoId);
                    throw new RuntimeException('تعذر تسجيل عملية الرفع بأمان');
                }
                // An unattached direct upload is always safe for automatic
                // retirement: the cleanup worker rechecks live lesson pointers.
                $candidate->forceFill([
                    'requires_review' => false,
                    'reviewed_at' => now(),
                    'reviewed_by' => $admin->id,
                ])->save();
                $advanced = BunnyDirectUpload::query()
                    ->whereKey($session->id)
                    ->where('status', 'allocating')
                    ->where('allocation_token', $allocationToken)
                    ->update([
                        'status' => 'pending',
                        'allocation_token' => null,
                        'updated_at' => now(),
                    ]);
                if ($advanced !== 1) {
                    throw $this->terminalOperation("انتهت محاولة تجهيز الرفع\nحاول مرة أخرى");
                }
                $session->refresh();

                return $this->payload(
                    $session,
                    $course,
                    $admin,
                    $title,
                    $size,
                    $mime,
                    $section,
                    $expectedAuthoringVersion
                );
            } catch (\Throwable $exception) {
                BunnyDirectUpload::query()
                    ->whereKey($session->id)
                    ->where('allocation_token', $allocationToken)
                    ->update(['status' => 'failed', 'allocation_token' => null, 'updated_at' => now()]);
                if ($videoId && $this->validGuid($videoId)) {
                    $failedCandidate = $this->bunny->queueVideoCleanup(
                        $videoId,
                        null,
                        'direct_upload_allocation_failed',
                        1
                    );
                    $failedCandidate?->forceFill([
                        'requires_review' => false,
                        'reviewed_at' => now(),
                        'reviewed_by' => $admin->id,
                    ])->save();
                }
                throw $exception;
            }
        });
    }

    private function retireStaleAllocation(BunnyDirectUpload $session, User $admin): void
    {
        $videoId = strtolower(trim((string) $session->video_guid));
        if ($this->validGuid($videoId)) {
            $this->queueAbandonedAllocation($videoId, $admin, 'direct_upload_stale_allocation');
            return;
        }
        $marker = '[rokn:' . strtolower((string) $session->idempotency_key) . ']';
        foreach ($this->bunny->findVideoGuidsByTitleMarker($marker) as $recoveredId) {
            $this->queueAbandonedAllocation(
                $recoveredId,
                $admin,
                'direct_upload_interrupted_allocation'
            );
        }
    }

    private function queueAbandonedAllocation(string $videoId, User $admin, string $reason): void
    {
        $candidate = $this->bunny->queueVideoCleanup($videoId, null, $reason, 1);
        if (!$candidate) {
            throw new RuntimeException('تعذر تسجيل الفيديو غير المكتمل بأمان');
        }
        $candidate->forceFill([
            'requires_review' => false,
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ])->save();
    }

    /** @return array<string, mixed> */
    public function authorization(Course $course, User $admin, string $claim): array
    {
        $payload = $this->claim($course, $admin, $claim);

        $session = DB::transaction(function () use ($payload): BunnyDirectUpload {
            $session = BunnyDirectUpload::query()
                ->whereKey((int) ($payload['upload_id'] ?? 0))
                ->lockForUpdate()
                ->first();
            if (!$session
                || $session->status !== 'pending'
                || $session->expires_at->isPast()
                || !hash_equals((string) $session->video_guid, (string) $payload['video_id'])) {
                throw $this->terminalClaim('تم استخدام هذا الرفع من قبل أو انتهت صلاحيته', 'claim');
            }

            $candidate = BunnyVideoCleanupCandidate::query()
                ->where('video_guid', $payload['video_id'])
                ->whereNull('remote_deleted_at')
                ->whereNull('last_attempt_at')
                ->lockForUpdate()
                ->first();
            if (!$candidate) {
                throw $this->terminalClaim('انتهت عملية الرفع أو تم استخدامها من قبل', 'claim');
            }

            $expiresAt = now()->addHours(max(2, (int) config('bunny.direct_upload_claim_ttl_hours', 24)));
            $session->forceFill(['expires_at' => $expiresAt])->save();
            $candidate->forceFill(['eligible_after' => $expiresAt])->save();

            return $session->fresh();
        }, 3);
        $payload['expires_at'] = $session->expires_at->getTimestamp();
        $refreshedClaim = $this->encryptedClaim($session, $payload);

        return array_merge([
            'video_id' => $payload['video_id'],
            'claim' => $refreshedClaim,
            'claim_expires_at' => gmdate(DATE_ATOM, (int) $payload['expires_at']),
        ], $this->bunny->directUploadAuthorization((string) $payload['video_id']));
    }

    /** @return array<string, mixed> */
    public function verifyForAttach(
        Course $course,
        User $admin,
        string $claim,
        ?CourseSection $section
    ): array {
        $payload = $this->claim($course, $admin, $claim);
        $this->assertPendingSession($payload);
        $claimedSectionId = $payload['section_id'] ?? null;
        $actualSectionId = $section?->id;
        if (($claimedSectionId === null) !== ($actualSectionId === null)
            || ($actualSectionId !== null && (int) $claimedSectionId !== (int) $actualSectionId)) {
            throw $this->terminalClaim('هذا الرفع لا يخص المقطع الحالي');
        }
        if (!BunnyVideoCleanupCandidate::query()
            ->where('video_guid', $payload['video_id'])
            ->whereNull('remote_deleted_at')
            ->whereNull('last_attempt_at')
            ->exists()) {
            throw $this->terminalClaim('تم استخدام هذا الرفع من قبل أو انتهت صلاحيته');
        }
        if (!$this->bunny->verifyDirectUpload((string) $payload['video_id'], (int) $payload['size'])) {
            throw ValidationException::withMessages([
                'bunny_video_claim' => "لم يكتمل رفع الفيديو بعد\nحاول الحفظ مرة أخرى بعد لحظات",
            ]);
        }

        return $payload;
    }

    /** Consume inside the same transaction that attaches the lesson pointer. */
    public function consume(string $videoId): void
    {
        $candidate = BunnyVideoCleanupCandidate::query()
            ->where('video_guid', $videoId)
            ->whereNull('remote_deleted_at')
            // Once cleanup has claimed the remote GUID, attaching it would
            // race a deletion already in flight. The moderator must allocate
            // a new upload instead of publishing a video that may disappear.
            ->whereNull('last_attempt_at')
            ->lockForUpdate()
            ->first();
        if (!$candidate) {
            throw $this->terminalClaim('تم استخدام هذا الرفع من قبل');
        }
        BunnyDirectUpload::query()
            ->where('video_guid', $videoId)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->update(['status' => 'attached', 'attached_at' => now(), 'updated_at' => now()]);
        $candidate->delete();
    }

    /** @return array<string, mixed> */
    private function claim(Course $course, User $admin, string $claim): array
    {
        $this->assertAuthoringContext($course, $admin, null);
        try {
            $payload = json_decode(Crypt::decryptString($claim), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            throw $this->terminalClaim('بيانات الرفع غير صالحة');
        }
        if (!is_array($payload)
            || (int) ($payload['v'] ?? 0) !== 2
            || (int) ($payload['course_id'] ?? 0) !== (int) $course->id
            || (int) ($payload['admin_id'] ?? 0) !== (int) $admin->id
            || (int) ($payload['expires_at'] ?? 0) < time()
            || !$this->validGuid((string) ($payload['video_id'] ?? ''))
            || (int) ($payload['size'] ?? 0) < 1
            || (int) ($payload['size'] ?? 0) > self::MAX_BYTES
            || !in_array((string) ($payload['mime'] ?? ''), self::MIMES, true)
            || trim((string) ($payload['title'] ?? '')) === ''
            || (int) ($payload['authoring_version'] ?? 0) < 1) {
            throw $this->terminalClaim('انتهت صلاحية الرفع أو لا يخص هذا الكورس');
        }
        $expectedVersion = (int) $payload['authoring_version'];
        $currentVersion = (int) Course::query()
            ->whereKey($course->id)
            ->value('authoring_version');
        if ($currentVersion !== $expectedVersion) {
            throw $this->terminalClaim("تغيّرت المسودة أثناء الرفع\nأعد تحميل الصفحة قبل المتابعة");
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function payload(
        BunnyDirectUpload $session,
        Course $course,
        User $admin,
        string $title,
        int $size,
        string $mime,
        ?CourseSection $section,
        int $expectedAuthoringVersion
    ): array {
        $claimPayload = [
            'v' => 2,
            'upload_id' => (int) $session->id,
            'video_id' => (string) $session->video_guid,
            'course_id' => (int) $course->id,
            'section_id' => $section ? (int) $section->id : null,
            'admin_id' => (int) $admin->id,
            'size' => $size,
            'mime' => $mime,
            'title' => $title,
            'expires_at' => $session->expires_at->getTimestamp(),
        ];
        $claimPayload['authoring_version'] = $expectedAuthoringVersion;
        $claim = $this->encryptedClaim($session, $claimPayload);

        return array_merge([
            'upload_endpoint' => 'https://video.bunnycdn.com/tusupload',
            'video_id' => (string) $session->video_guid,
            'claim' => $claim,
            'claim_expires_at' => $session->expires_at->toIso8601String(),
        ], $this->bunny->directUploadAuthorization((string) $session->video_guid));
    }

    /** @param array<string, mixed> $payload */
    private function encryptedClaim(BunnyDirectUpload $session, array $payload): string
    {
        $payload['v'] = 2;
        $payload['upload_id'] = (int) $session->id;
        $payload['video_id'] = (string) $session->video_guid;
        $payload['expires_at'] = $session->expires_at->getTimestamp();

        return Crypt::encryptString((string) json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $payload */
    private function assertPendingSession(array $payload): void
    {
        $session = BunnyDirectUpload::query()->find((int) ($payload['upload_id'] ?? 0));
        if (!$session
            || $session->status !== 'pending'
            || $session->expires_at->isPast()
            || !hash_equals((string) $session->video_guid, (string) $payload['video_id'])) {
            throw $this->terminalClaim('تم استخدام هذا الرفع من قبل أو انتهت صلاحيته');
        }
    }

    private function terminalClaim(string $message, string $field = 'bunny_video_claim'): ValidationException
    {
        return ValidationException::withMessages([
            $field => $message,
            'bunny_video_claim_terminal' => 'claim_unavailable',
        ]);
    }

    private function terminalOperation(string $message): ValidationException
    {
        return ValidationException::withMessages([
            'idempotency_key' => $message,
            'bunny_upload_operation_terminal' => 'operation_unavailable',
        ]);
    }

    private function assertAuthoringContext(Course $course, User $admin, ?CourseSection $section): void
    {
        if (!in_array(strtolower(trim((string) $admin->role)), ['admin', 'moderator'], true)) {
            abort(403);
        }
        if (DatabaseCapabilities::hasTable('course_authoring_revisions')
            && CourseAuthoringRevision::query()
                ->where('revision_course_id', $course->id)
                ->where('status', CourseAuthoringRevision::ARCHIVED)
                ->exists()) {
            throw ValidationException::withMessages([
                'authoring_version' => "نُشرت هذه المسودة بالفعل\nأعد فتح استوديو الكورس قبل رفع الفيديو",
            ])->status(409);
        }
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => 'حوّل الكورس إلى مسودة قبل استبدال الفيديو',
            ]);
        }
        if ($section && (int) $section->course_id !== (int) $course->id) {
            abort(404);
        }
    }

    private function assertExpectedAuthoringVersion(Course $course, int $expectedVersion): void
    {
        $currentVersion = (int) Course::query()->whereKey($course->id)->value('authoring_version');
        if ($expectedVersion < 1 || $currentVersion !== $expectedVersion) {
            throw $this->terminalOperation("تغيّرت المسودة منذ فتح الصفحة\nأعد تحميلها قبل رفع الفيديو");
        }
    }

    private function validGuid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $value) === 1;
    }
}
