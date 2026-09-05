<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Support\DatabaseCapabilities;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * One revision protects the complete course authoring aggregate. A module,
 * section, project or attachment edit is not independent from the outline the
 * moderator saw when they started editing.
 */
final class CourseAuthoringConcurrencyService
{
    public function lock(Request $request, Course $course): Course
    {
        $locked = $this->lockMutableCourse($course);
        $submitted = $request->input('authoring_version');

        if ($submitted === null || (int) $submitted !== (int) $locked->authoring_version) {
            throw ValidationException::withMessages([
                'authoring_version' => [
                    "تغيّر الكورس منذ فتح هذه الصفحة\nأعد تحميلها ثم راجع التعديل قبل الحفظ",
                ],
            ])->status(409);
        }

        return $locked;
    }

    public function advance(Course $course): int
    {
        $course->increment('authoring_version');

        return (int) $course->authoring_version;
    }

    public function lockExpected(Course $course, int $expectedVersion): Course
    {
        $locked = $this->lockMutableCourse($course);
        if ((int) $locked->authoring_version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'authoring_version' => [
                    "تغيّر الكورس أثناء الحفظ\nراجع آخر تعديل قبل النشر",
                ],
            ])->status(409);
        }

        return $locked;
    }

    private function lockMutableCourse(Course $course): Course
    {
        $identity = DatabaseCapabilities::hasTable('course_authoring_revisions')
            ? CourseAuthoringRevision::query()
                ->where('revision_course_id', $course->id)
                ->latest('id')
                ->first()
            : null;
        if (!$identity) {
            $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
            $this->assertMutableDraft($locked, null);

            return $locked;
        }

        // Match publish/draftFor everywhere: canonical -> revision -> draft.
        // A long upload may finish while another moderator publishes; locking
        // and revalidating the slot before the draft row prevents its final
        // write from landing in the newly archived graph.
        Course::query()->whereKey($identity->canonical_course_id)
            ->lockForUpdate()->firstOrFail();
        $revision = CourseAuthoringRevision::query()->whereKey($identity->id)
            ->lockForUpdate()->firstOrFail();
        $locked = Course::query()->whereKey($course->id)->lockForUpdate()->firstOrFail();
        $this->assertMutableDraft($locked, $revision);

        return $locked;
    }

    private function assertMutableDraft(
        Course $course,
        ?CourseAuthoringRevision $revision
    ): void {
        $neverPublishedDraft = !$revision
            && (int) ($course->last_published_authoring_version ?? 0) < 1
            && $course->published_at === null
            && !(bool) $course->is_catalog_visible
            && (bool) $course->is_coming_soon;
        $ownsActiveDraftSlot = $revision
            && (int) $revision->revision_course_id === (int) $course->id
            && $revision->status === CourseAuthoringRevision::DRAFT
            && hash_equals(
                'course-draft:' . (int) $revision->canonical_course_id,
                (string) $revision->active_slot
            );
        if ($neverPublishedDraft || $ownsActiveDraftSlot) return;

        throw ValidationException::withMessages([
            'authoring_version' => [
                "نُشرت نسخة أحدث أثناء الحفظ\nأعد فتح استوديو الكورس ثم أرسل التعديل إلى المسودة الجديدة",
            ],
        ])->status(409);
    }
}
