<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ApiToken;
use App\Support\AdminStudentSearch;
use App\Support\BusinessClock;
use App\Support\DatabaseCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** Public session identifiers, never bearer hashes, form the dashboard read projection. */
final class AdminDeviceSessionReadService
{
    public function ready(): bool
    {
        return DatabaseCapabilities::hasColumn((new ApiToken)->getTable(), 'session_id');
    }

    /** @return Builder<ApiToken> */
    private function active(): Builder
    {
        return ApiToken::query()->whereHasNotExpired()->whereNotNull('session_id');
    }

    public function summary(): array
    {
        if (! $this->ready()) {
            return [];
        }

        $platform = "CASE WHEN platform IN ('android', 'ios') THEN platform ELSE 'other' END";

        return $this->active()->selectRaw($platform.' AS platform, COUNT(*) AS total')
            ->groupByRaw($platform)->pluck('total', 'platform')->all();
    }

    public function paginate(?string $search, ?string $platform, int $perPage, int $page): LengthAwarePaginator
    {
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 50;
        $page = max(1, $page);
        if (! $this->ready()) {
            return new LengthAwarePaginator([], 0, $perPage, $page);
        }
        $search = trim(mb_substr((string) $search, 0, 160));
        $escaped = '%'.AdminStudentSearch::escape($search).'%';
        $sessions = $this->active()->with('user:id,name,name_ar,email')
            ->select(['session_id', 'user_id', 'platform', 'device_class', 'app_version', 'app_build', 'auth_provider', 'issued_at', 'last_used_at', 'expired_at'])
            ->when(in_array($platform, ['android', 'ios', 'other'], true), fn ($query) => $platform === 'other'
                ? $query->where(fn ($platforms) => $platforms->whereNull('platform')->orWhereNotIn('platform', ['android', 'ios']))
                : $query->where('platform', $platform))
            ->when($search !== '', fn ($query) => $query->whereHas('user', fn ($users) => $users->where(function ($match) use ($escaped): void {
                $match->whereRaw("name LIKE ? ESCAPE '!'", [$escaped])
                    ->orWhereRaw("name_ar LIKE ? ESCAPE '!'", [$escaped])->orWhereRaw("email LIKE ? ESCAPE '!'", [$escaped]);
            })))
            ->orderByDesc('last_used_at')->orderByDesc('issued_at')->orderByDesc('session_id')
            ->paginate($perPage, ['*'], 'page', $page);
        $sessions->setCollection($sessions->getCollection()->map(fn (ApiToken $session): array => [
            'session_id' => $session->session_id, 'user_name' => $session->user->name ?? 'حساب محذوف',
            'email' => $session->user->email ?? '—', 'platform' => $session->platform,
            'device_class' => $session->device_class === 'tablet' ? 'جهاز لوحي' : 'هاتف',
            'app_version' => $session->app_version ?: '—', 'app_build' => $session->app_build,
            'auth_provider' => $session->auth_provider ?: '—',
            'issued_at' => BusinessClock::format($session->issued_at),
            'last_used_at' => BusinessClock::format($session->last_used_at ?: $session->issued_at),
            'expired_at' => BusinessClock::format($session->expired_at),
        ])->keyBy('session_id'));

        return $sessions;
    }
}
