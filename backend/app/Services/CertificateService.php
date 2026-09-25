<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\StudentNotificationIntent;

use App\Support\StorageWriteOptions;
use App\Support\UnicodeText;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertificateService
{
    public function __construct(
        private readonly FinancialEntitlementHoldReadService $holds,
        private readonly CertificateEligibilityService $eligibility,
        private readonly CourseRevisionResolver $revisionResolver,
        private readonly CertificateIssuanceSnapshotService $snapshots,
        private readonly CertificateQrDestinationService $qrDestinations,
        private readonly CertificateArtworkRenderer $artwork,
        private readonly LegacyCertificateArtworkRenderer $legacyArtwork,
        private readonly StudentNotificationService $notifications
    ) {
    }

    /**
     * Generate (or retrieve existing) certificate for a user + course.
     */
    public function generate(
        User $user,
        Course $course,
        ?string $requestedHolderName = null,
        bool $renderArtifact = true
    ): ?Certificate
    {
        $certificate = Certificate::where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->first();
        $latestEnrollment = CourseEnrollment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->latest('id')
            ->first();

        // An issued credential is an immutable achievement snapshot. Reading
        // or rebuilding its artifact does not depend on a subscription that
        // may expire later. Financial voids remain authoritative through the
        // revocation state and the hold checked here before any recovery.
        if ($certificate) {
            if (
                !$certificate->isActiveCredential()
                || ($latestEnrollment && $this->holds
                    ->enrollmentHasActiveHold($latestEnrollment, ['course']))
            ) {
                return null;
            }
        }

        $requestedHolderName = UnicodeText::limit(
            UnicodeText::clean($requestedHolderName, false),
            120
        );
        // Creating a credential is an explicit learner action because this
        // text becomes an immutable identity snapshot. Recovery of its
        // pending row remains automatic and uses its stored snapshot.
        if (!$certificate && UnicodeText::graphemeLength($requestedHolderName) < 2) {
            return null;
        }

        // Eligibility creates a credential; it does not expire one already
        // issued. A pending or lost artifact can therefore be rebuilt from its
        // immutable row even if progress tables are later archived.
        if (!$certificate && !$this->eligibility->for($user, $course)['available']) {
            return null;
        }

        if (!$certificate) {
            // Create the DB record first so the public credential ID is stable
            // across retries. Lock the course before taking its editorial
            // snapshot: a moderator may move it to draft or change the
            // certificate wording while this request is waiting. The fresh,
            // locked aggregate is the only version that may create a new
            // credential. Lock the user first so issuance follows the same
            // boundary as account deletion and financial reversal, then lock
            // the editorial aggregate before reading its snapshot.
            try {
                $certificate = DB::transaction(function () use (
                    $user,
                    &$course,
                    $requestedHolderName
                ): ?Certificate {
                    $lockedUser = User::query()
                        ->whereKey($user->id)
                        ->lockForUpdate()
                        ->first();
                    if (!$lockedUser) {
                        return null;
                    }

                    $lockedCourse = Course::query()
                        ->whereKey($course->id)
                        ->lockForUpdate()
                        ->first();
                    if (
                        !$lockedCourse
                        || !$this->eligibility->for($lockedUser, $lockedCourse)['available']
                    ) {
                        return null;
                    }

                    $course = $lockedCourse;
                    $verificationLevel = $this->verificationLevel(
                        $lockedUser,
                        $lockedCourse
                    );
                    $publicId = (string) Str::uuid();
                    $snapshot = $this->snapshots->forIssuance($lockedUser, $lockedCourse, $publicId);
                    if ($snapshot === null) {
                        return null;
                    }
                    $courseName = $this->courseName($lockedCourse);
                    if ($courseName === '') {
                        return null;
                    }
                    $createAttributes = [
                        'public_id'    => $publicId,
                        'user_id'      => $lockedUser->id,
                        'course_id'    => $lockedCourse->id,
                        'image_path'   => 'pending',
                        'generated_at' => now(),
                        'status'       => 'active',
                    ];
                    $createAttributes['holder_name'] = $requestedHolderName;
                    $createAttributes['course_name'] = $courseName;
                    $createAttributes['verification_level'] = $verificationLevel;
                    $createAttributes = array_merge($createAttributes, $snapshot);

                    return Certificate::query()
                        ->where('user_id', $lockedUser->id)
                        ->where('course_id', $lockedCourse->id)
                        ->first()
                        ?: Certificate::create($createAttributes);
                }, 3);
                if (!$certificate) {
                    return null;
                }
            } catch (\Illuminate\Database\QueryException $e) {
                $certificate = Certificate::where('user_id', $user->id)
                    ->where('course_id', $course->id)
                    ->first();
                if (!$certificate) {
                    throw $e;
                }
                if (!$certificate->isActiveCredential()) {
                    return null;
                }
            }
        }

        if (!$certificate->hasCompleteCredentialSnapshot()) {
            // A request creates the immutable identity/editorial snapshot in
            // one transaction. Recovery never invents missing claims from
            // mutable profile, course or configuration state.
            return null;
        }
        if ($certificate->hasStoredArtifact()) {
            $certificate->forceFill(['artifact_checked_at' => now()])->save();
            return $certificate;
        }

        // The HTTP request only reserves the immutable credential. Rendering
        // is intentionally queue-backed: image composition and remote storage
        // must not hold an app request open or turn a healthy issue action into
        // a client timeout. The recovery job calls this method with the default
        // and owns the generation lease below.
        if (!$renderArtifact) {
            return $certificate->fresh();
        }

        $leaseId = (string) Str::uuid();
        $leaseStaleBefore = now()->subMinutes(max(
            2,
            (int) config('operations.certificate_recovery_stale_minutes', 5)
        ));
        $certificate = DB::transaction(function () use (
            $certificate,
            $leaseId,
            $leaseStaleBefore
        ): ?Certificate {
            $locked = Certificate::query()->lockForUpdate()->find($certificate->id);
            if (
                !$locked
                || !$locked->isActiveCredential()
                || !User::query()->whereKey($locked->user_id)->exists()
            ) {
                return null;
            }
            if (
                trim((string) $locked->generation_lease_id) !== ''
                && $locked->updated_at?->isAfter($leaseStaleBefore)
            ) {
                return null;
            }
            $locked->forceFill([
                'generation_lease_id' => $leaseId,
            ])->save();
            return $locked->fresh();
        }, 3);
        if (!$certificate) {
            return null;
        }

        // The issue date is credential history, not the time of an artifact
        // recovery. Retrying a pending or lost image keeps the original date.
        $previousPath = trim((string) $certificate->image_path);
        $filePath = $this->createCertificateImage(
            $certificate,
            $certificate->generated_at,
            $leaseId
        );

        if (!$filePath) {
            // Keep the pending row as the durable recovery marker. The queued
            // recovery worker or an authenticated recovery request can safely retry.
            Certificate::query()
                ->whereKey($certificate->id)
                ->where('generation_lease_id', $leaseId)
                ->update(['generation_lease_id' => null]);
            return null;
        }

        $updateAttributes = [
            'image_path' => $filePath,
            'status' => 'active',
            'generation_lease_id' => null,
        ];
        $updateAttributes += [
            'recovery_attempts' => 0,
            'recovery_next_attempt_at' => null,
            'recovery_failed_at' => null,
            'recovery_failure_code' => null,
            'artifact_checked_at' => now(),
        ];
        $committed = Certificate::query()
            ->whereKey($certificate->id)
            ->where('generation_lease_id', $leaseId)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->whereHas('user')
            ->update($updateAttributes);
        if ($committed !== 1) {
            $this->deleteCertificateArtifact($filePath);
            return null;
        }
        $certificate->refresh();
        if (
            $previousPath !== ''
            && $previousPath !== 'pending'
            && $previousPath !== $filePath
        ) {
            $this->deleteCertificateArtifact($previousPath);
        }

        $this->notifications->notifyUser(
            $user,
            new StudentNotificationIntent(
                notificationType: StudentNotificationService::TYPE_CERTIFICATE_READY,
                titleAr: 'شهادتك جاهزة',
                titleEn: 'Your certificate is ready',
                messageAr: 'أكملت الكورس وأصبحت شهادتك جاهزة',
                messageEn: 'You completed the course and your certificate is ready.',
                link: 'rokn://profile/certificates',
                notifiableType: Course::class,
                notifiableId: (int) $course->id,
                deliveryKey: 'certificate-ready:' . $certificate->id,
                templateVariables: ['course' => (string) ($course->name_ar ?: $course->name_en)]
            )
        );

        return $certificate;
    }

    private function verificationLevel(
        User $user,
        Course $course
    ): string
    {
        // A course may contain several graduation projects. The certificate
        // label describes the strongest verified evidence in that course, not
        // whichever project happened to be returned first by an arbitrary query.
        $graduationProjectIds = Project::query()
            ->where('is_graduation_project', true)
            ->whereHas('section', fn ($sections) => $sections->where('course_id', $course->id))
            ->pluck('id');
        $equivalentProjectIds = $graduationProjectIds->flatMap(
            fn ($projectId) => $this->revisionResolver->equivalentEntityIds(
                Project::class,
                (int) $projectId
            )
        )->unique()->values();
        $humanReviewed = $equivalentProjectIds->isNotEmpty() && ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->whereIn('project_id', $equivalentProjectIds)
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->where('review_source', 'admin_manual')
            ->exists();

        return $humanReviewed ? 'reviewed_project' : 'completion';
    }

    /* ------------------------------------------------------------------
     * Image generation
     * ----------------------------------------------------------------*/

    private function createCertificateImage(
        Certificate $certificate,
        \DateTimeInterface $generatedAt,
        string $generationLeaseId
    ): ?string
    {
        try {
            $destination = $this->qrDestinations->for($certificate);
            if ($destination === null) return null;

            // Dispatch from immutable issuance metadata, never today's course settings.
            $version = trim((string) $certificate->certificate_design_version);
            $encoded = match ($version) {
                'editorial_v1', CertificateArtworkRenderer::VERSION => $this->artwork->render($certificate, $destination),
                '' => $this->legacyArtwork->render($certificate, $generatedAt, $destination),
                default => throw new \RuntimeException('Unsupported certificate design version.'),
            };
            if ($encoded === null || $encoded === '') return null;

            // ----- Save -----
            // Public certificate images must not be enumerable by numeric user/course IDs.
            $filename = 'certificate_' . $certificate->public_id
                . '_' . str_replace('-', '', $generationLeaseId) . '.png';
            $storagePath = 'certificates/' . $filename;
            $disk = (string) config('certificate.disk', 'public');
            app(StoredFileDeletionService::class)
                ->trackPotentialOrphan($disk, $storagePath, 60);
            $stored = Storage::disk($disk)->put(
                $storagePath,
                $encoded,
                StorageWriteOptions::forDisk($disk, 'private')
            );

            if (!$stored) {
                throw new \RuntimeException('Certificate artifact could not be stored.');
            }

            return $storagePath;
        } catch (\Throwable $e) {
            report($e);
            return null;
        }
    }

    private function deleteCertificateArtifact(string $path): void
    {
        try {
            app(StoredFileDeletionService::class)->deleteOrQueue(
                (string) config('certificate.disk', 'public'),
                $path
            );
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function courseName(Course $course): string
    {
        return $this->firstText([
            $course->getRawOriginal('name_ar'),
            $course->getRawOriginal('name_en'),
        ]);
    }

    /** @param array<int, mixed> $values */
    private function firstText(array $values): string
    {
        foreach ($values as $value) {
            $text = UnicodeText::clean($value, false);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

}
