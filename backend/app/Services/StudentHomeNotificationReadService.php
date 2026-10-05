<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\StudentNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** A presentation slice of the existing inbox, never a second delivery queue. */
final readonly class StudentHomeNotificationReadService
{
    public function __construct(
        private CourseCatalogueQueryService $catalogue,
        private CourseEntitlementService $entitlements,
        private StudentNotificationPresentationService $presentation,
    ) {
    }

    public function constrainCandidates(Builder $query): Builder
    {
        // Publication and discovery are checked at read time, not just when
        // the dashboard originally sent the campaign. Filter before paging so
        // unrelated inbox rows cannot hide an older course announcement.
        return $query->unread()
            // An old delivered marketing row stays in the inbox, but must not
            // interrupt Home after the learner disables offers/news.
            ->whereHas('user', fn (Builder $users) => $users->where('marketing_notifications_enabled', true))
            ->whereIn('notification_type', ['new_course', 'course_promotion', 'course_recommendation'])
            ->whereHasMorph('notifiable', [Course::class], function (Builder $courses): void {
                $this->catalogue->constrainPublic($courses)->where('is_coming_soon', false);
            });
    }

    /** @return array<int,array{id:int,title:string,image_url:string}> */
    public function courseCards(Collection $notifications, int $userId): array
    {
        $notifications->loadMorph('notifiable', [Course::class => ['photo']]);
        $courses = $notifications->map(fn (StudentNotification $row) => $row->notifiable)
            ->filter(fn ($course): bool => $course instanceof Course)->keyBy('id');
        // Reuse the same captured rights/financial-hold reader as catalogue
        // and learning. Do not invent a popup-specific definition of ownership.
        $entitlements = $this->entitlements->entitlementsFor($userId, $courses->keys()->all());
        $cards = [];
        foreach ($notifications as $notification) {
            $course = $notification->notifiable;
            if (!$course instanceof Course || !$course->isAvailableForNewPurchase()
                || ($entitlements[$course->id]['has_learning_access'] ?? false)) {
                continue;
            }
            $card = $this->presentation->homeCourse($course);
            if ($card !== null) $cards[(int) $notification->id] = $card;
        }

        return $cards;
    }
}
