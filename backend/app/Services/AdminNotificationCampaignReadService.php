<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\NotificationCampaign;
use App\Models\User;
use App\Support\AdminStudentSearch;
use Illuminate\Database\Eloquent\Builder;

final class AdminNotificationCampaignReadService
{
    public function student(int $id): User
    {
        return User::query()->students()->findOrFail($id);
    }

    public function courseOptions(string $search): array
    {
        $search = trim($search);

        return Course::query()->where('is_coming_soon', false)
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $literal = '%'.AdminStudentSearch::escape($search).'%';
                $query->whereRaw("name_ar LIKE ? ESCAPE '!'", [$literal])->orWhereRaw("name_en LIKE ? ESCAPE '!'", [$literal]);
                if (ctype_digit($search)) {
                    $query->orWhereKey((int) $search);
                }
            }))->orderByDesc('id')->limit(50)->pluck('name_ar', 'id')->all();
    }

    public function courseName(int $id): ?string
    {
        return Course::query()->whereKey($id)->value('name_ar');
    }

    /** @return Builder<NotificationCampaign> */
    public function query(): Builder
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
            ]);
    }
}
