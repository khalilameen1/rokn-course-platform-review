<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Support\AdminStudentSearch;
use App\Support\BusinessClock;
use App\Support\DatabaseCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only listing of the dashboard audit trail (filter by actor, action and
 * local date; newest first), in the style of Filament/Nova resource tables.
 * Nothing here can edit or delete entries; retention pruning stays in
 * PruneOperationalData.
 */
final class AdminAuditLogReadService
{
    /** @return Builder<AdminAuditLog> */
    public function query(array $filters = []): Builder
    {
        $from = ! empty($filters['from']) ? BusinessClock::localDayRangeUtc($filters['from'])[0] : null;
        $toExclusive = ! empty($filters['to']) ? BusinessClock::localDayRangeUtc($filters['to'])[1] : null;
        $action = trim((string) ($filters['action'] ?? ''));

        return AdminAuditLog::query()
            ->with('actor:id,name,name_ar,role,deleted_at')
            ->when(! empty($filters['actor_id']), fn ($query) => $query->where('actor_id', (int) $filters['actor_id']))
            ->when($action !== '', function ($query) use ($action): void {
                $query->where(function ($match) use ($action): void {
                    $escaped = AdminStudentSearch::escape($action);
                    $match->whereRaw("route_name LIKE ? ESCAPE '!'", [$escaped.'%'])
                        ->orWhereRaw("path LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
                });
            })
            ->when($from, fn ($query, $value) => $query->where('occurred_at', '>=', $value))
            ->when($toExclusive, fn ($query, $value) => $query->where('occurred_at', '<', $value))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');
    }

    /** @return Collection<int, User> */
    public function actors(): Collection
    {
        return User::withTrashed()
            ->whereIn('role', ['admin', 'moderator'])
            ->orderBy('name')
            ->get(['id', 'name', 'name_ar', 'role', 'deleted_at']);
    }

    public function recordsChanges(): bool
    {
        return DatabaseCapabilities::hasColumn('admin_audit_logs', 'changes');
    }
}
