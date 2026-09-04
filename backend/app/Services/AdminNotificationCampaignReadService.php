<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class AdminNotificationCampaignReadService
{
    public function campaigns(): LengthAwarePaginator
    {
        return NotificationCampaign::query()
            ->withCount([
                'notifications as attempted_count' => fn ($query) => $query->whereNotNull('push_attempted_at'),
                'notifications as provider_accepted_count' => fn ($query) => $query->whereNotNull('push_sent_at'),
                'notifications as read_count' => fn ($query) => $query->where('is_read', true),
                'notifications as push_failed_count' => fn ($query) => $query
                    ->whereNotNull('push_failed_at')->whereNull('push_sent_at'),
                'notifications as push_partial_count' => fn ($query) => $query
                    ->whereNotNull('push_sent_at')->whereNotNull('push_failed_at'),
            ])
            ->orderByRaw('COALESCE(scheduled_at, queued_at, created_at) DESC')
            ->paginate(30);
    }

    /** @return array{courses:\Illuminate\Support\Collection,targetStudent:?User} */
    public function authoringContext(?int $targetUserId, ?string $courseSearch = null): array
    {
        $courseSearch = trim((string) $courseSearch);
        $courses = Course::query()
            ->where('is_coming_soon', false)
            ->when($courseSearch !== '', static function ($query) use ($courseSearch): void {
                $query->where(static function ($courseQuery) use ($courseSearch): void {
                    $courseQuery
                        ->where('name_ar', 'like', '%'.$courseSearch.'%')
                        ->orWhere('name_en', 'like', '%'.$courseSearch.'%');
                    if (ctype_digit($courseSearch)) {
                        $courseQuery->orWhereKey((int) $courseSearch);
                    }
                });
            })
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'name_ar', 'name_en']);
        $targetStudent = $targetUserId
            ? User::query()->students()->findOrFail($targetUserId)
            : null;

        return compact('courses', 'targetStudent', 'courseSearch');
    }
}
