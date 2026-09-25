<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseGrantClaim;
use App\Models\User;
use App\Support\CourseCodeRejection;

/** Read-only admission rules; the writer passes freshly locked user/code/course rows. */
final class CourseCodeEligibilityService
{
    public function rejectionFor(CourseCode $code, User $user, ?Course $course = null): ?CourseCodeRejection
    {
        if ($code->type !== 'course') {
            return CourseCodeRejection::LEGACY_RETIRED;
        }
        $course ??= Course::query()->find($code->course_id);
        if (!$this->courseAvailable($code, $course)) {
            return CourseCodeRejection::COURSE_UNAVAILABLE;
        }
        if ($this->hasReachedGrantLimit($code, $user)) {
            return CourseCodeRejection::GRANT_ALREADY_CLAIMED;
        }
        if (!$this->emailEligible($code, $user)) {
            return CourseCodeRejection::EMAIL_NOT_ELIGIBLE;
        }
        if ($code->is_expired) {
            return CourseCodeRejection::EXPIRED;
        }
        if ($code->is_not_yet_active) {
            return CourseCodeRejection::NOT_STARTED;
        }
        if (!$code->is_active) {
            return CourseCodeRejection::DISABLED;
        }
        if ($code->used_count >= $code->max_uses) {
            return CourseCodeRejection::EXHAUSTED;
        }
        return $code->usages()->where('user_id', $user->id)->exists()
            ? CourseCodeRejection::ALREADY_USED
            : null;
    }

    public function courseAvailable(CourseCode $code, ?Course $course): bool
    {
        return $course !== null
            && (int) $course->id === (int) $code->course_id
            && (bool) $course->is_catalog_visible
            && $course->isPublishedForLearning();
    }

    public function hasReachedGrantLimit(CourseCode $code, User $user): bool
    {
        if (!$code->isInstitutionalGrant()) {
            return false;
        }
        $normalizedEmail = mb_strtolower(trim((string) $user->email));

        // Account deletion/reassignment does not make an acquisition claim new.
        return CourseGrantClaim::query()->where(function ($query) use ($user, $normalizedEmail): void {
            $query->where('user_id', $user->id);
            if ($normalizedEmail !== '') {
                $query->orWhere('normalized_email_hash', CourseGrantClaim::emailHash($normalizedEmail));
            }
        })->exists();
    }

    private function emailEligible(CourseCode $code, User $user): bool
    {
        $domains = collect($code->allowed_email_domains ?? [])
            ->map(fn ($domain) => ltrim(mb_strtolower(trim((string) $domain)), '@'))
            ->filter()->values();
        if ($domains->isEmpty()) {
            return true;
        }
        if (!$user->email_verified_at) {
            return false;
        }
        $email = mb_strtolower((string) $user->email);
        $domain = str_contains($email, '@') ? substr(strrchr($email, '@'), 1) : '';

        return $domain !== '' && $domains->contains($domain);
    }
}
