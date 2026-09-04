<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CoursePdf;
use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;

final class CourseModuleAccessService
{
    public function __construct(
        private CourseChatAccessService $courseAccess
    )
    {
    }

    public function hasCourseAccess(User $user, Course $course): bool
    {
        if (
            !$user->exists
            || !(bool) $user->active
            || $user->trashed()
        ) {
            return false;
        }

        return $this->courseAccess->hasLearningAccess(
            (int) $user->id,
            (int) $course->id
        );
    }

    public function canDownloadPdf(User $user, Course $course, CoursePdf $pdf): bool
    {
        return (int) $pdf->course_id === (int) $course->id
            && (bool) $pdf->is_active
            && $this->hasCourseAccess($user, $course);
    }

    /** @return array{download_url:string,expires_in_seconds:int,download_url_expires_at:string,download_url_is_temporary:bool} */
    public function temporaryPdfDownloadContract(User $user, Course $course, CoursePdf $pdf): array
    {
        $expiresAt = now()->addMinutes($this->downloadLifetimeMinutes());

        return $this->downloadContract(URL::temporarySignedRoute(
            'api.course-pdfs.download',
            $expiresAt,
            [
                'course' => $course->getKey(),
                'pdf' => $pdf->getKey(),
                'owner' => $this->ownerClaim($user),
            ]
        ), $expiresAt);
    }

    /** Resolve the opaque owner claim after the controller validates its signature. */
    public function userFromOwnerClaim(mixed $claim): ?User
    {
        if (!is_string($claim) || $claim === '') {
            return null;
        }

        try {
            $userId = filter_var(Crypt::decryptString($claim), FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1],
            ]);
        } catch (DecryptException) {
            return null;
        }

        return $userId === false ? null : User::query()->find($userId);
    }

    private function ownerClaim(User $user): string
    {
        return Crypt::encryptString((string) $user->getKey());
    }

    public function downloadLifetimeMinutes(): int
    {
        return max(5, min(60, (int) config('course_attachments.signed_url_minutes', 30)));
    }

    /** @return array{download_url:string,expires_in_seconds:int,download_url_expires_at:string,download_url_is_temporary:bool} */
    private function downloadContract(string $url, \DateTimeInterface $expiresAt): array
    {
        return [
            'download_url' => $url,
            'expires_in_seconds' => $this->downloadLifetimeMinutes() * 60,
            'download_url_expires_at' => $expiresAt->format(DATE_ATOM),
            'download_url_is_temporary' => true,
        ];
    }
}
