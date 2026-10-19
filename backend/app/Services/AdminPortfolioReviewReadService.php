<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortfolioItem;
use App\Models\User;
use App\Support\AdminStudentSearch;
use Illuminate\Database\Eloquent\Builder;

final class AdminPortfolioReviewReadService
{
    public const STATUSES = ['pending' => 'بانتظار المراجعة', 'approved' => 'معتمد', 'rejected' => 'مرفوض', 'suspended' => 'موقوف'];

    /** @return Builder<User> */
    public function query(): Builder
    {
        return User::query()->whereNotNull('portfolio_slug')
            ->withCount(['portfolioItems as selected_projects_count' => fn (Builder $items) => (new PortfolioItem)->scopeShareable($items)])
            ->orderBy('updated_at')->orderBy('id');
    }

    /** @param Builder<User> $query @return Builder<User> */
    public function filterStatus(Builder $query, ?string $status): Builder
    {
        if (blank($status)) {
            return $query;
        }
        if ($status === 'suspended') {
            return $query->whereNotNull('portfolio_sharing_suspended_at');
        }
        $query->whereNull('portfolio_sharing_suspended_at')->where('portfolio_sharing_status', $status);
        if ($status === 'pending') {
            $query->whereHas('portfolioItems', fn (Builder $items) => $items->shareable());
        }

        return $query;
    }

    /** Public portfolio names only; this domain does not grant student-contact search. @param Builder<User> $query @return Builder<User> */
    public function search(Builder $query, string $name): Builder
    {
        $pattern = '%'.AdminStudentSearch::escape(trim($name)).'%';

        return $query->where(fn (Builder $names) => $names->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereRaw("name_ar LIKE ? ESCAPE '!'", [$pattern])->orWhereRaw("name_en LIKE ? ESCAPE '!'", [$pattern]));
    }

    public static function status(User $owner): string
    {
        return $owner->portfolio_sharing_suspended_at !== null ? 'suspended' : (string) $owner->portfolio_sharing_status;
    }
}
