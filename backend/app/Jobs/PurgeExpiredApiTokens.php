<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PurgeExpiredApiTokens implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [60, 300];

    public function __construct()
    {
        $this->onQueue((string) config('queue.channels.operations', 'operations'));
    }

    public function handle(): void
    {
        $table = (string) config('multiple-tokens-auth.table', 'api_tokens');
        if (! Schema::hasTable($table)) {
            return;
        }

        do {
            $hasDeviceId = Schema::hasColumn($table, 'device_id');
            $columns = ['token', 'user_id'];
            if ($hasDeviceId) {
                $columns[] = 'device_id';
            }
            $tokens = DB::table($table)
                ->whereNotNull('expired_at')
                ->where('expired_at', '<', now())
                ->orderBy('expired_at')
                ->limit(1000)
                ->get($columns);

            if ($tokens->isNotEmpty()) {
                DB::table($table)->whereIn('token', $tokens->pluck('token'))->delete();
                $this->removeOrphanedPushRegistrations($table, $tokens, $hasDeviceId);
            }
        } while ($tokens->count() === 1000);
    }

    private function removeOrphanedPushRegistrations(string $tokenTable, $expired, bool $hasDeviceId): void
    {
        if (
            ! $hasDeviceId
            || ! Schema::hasTable('user_device_tokens')
            || ! Schema::hasColumn('user_device_tokens', 'device_id')
        ) {
            return;
        }

        $candidates = $expired
            ->filter(static fn ($row): bool => trim((string) ($row->device_id ?? '')) !== '')
            ->mapWithKeys(static fn ($row): array => [
                ((int) $row->user_id).':'.trim((string) $row->device_id) => [
                    'user_id' => (int) $row->user_id,
                    'device_id' => trim((string) $row->device_id),
                ],
            ]);
        if ($candidates->isEmpty()) {
            return;
        }

        $activeQuery = DB::table($tokenTable)
            ->whereIn('user_id', $candidates->pluck('user_id')->unique())
            ->whereIn('device_id', $candidates->pluck('device_id')->unique())
            ->where(function ($expiry): void {
                // Null means a non-expiring active token in the legacy schema.
                // Ignoring it detached push from a device that was still
                // signed in through another session.
                $expiry->whereNull('expired_at')->orWhere('expired_at', '>', now());
            });
        if (Schema::hasColumn($tokenTable, 'revoked_at')) {
            $activeQuery->whereNull('revoked_at');
        }
        $activePairs = $activeQuery
            ->get(['user_id', 'device_id'])
            ->mapWithKeys(static fn ($row): array => [
                ((int) $row->user_id).':'.trim((string) $row->device_id) => true,
            ]);
        $orphanedPairs = $candidates->reject(
            static fn (array $pair, string $key): bool => $activePairs->has($key)
        );
        if ($orphanedPairs->isEmpty()) {
            return;
        }

        DB::table('user_device_tokens')
            ->whereIn('user_id', $orphanedPairs->pluck('user_id')->unique())
            ->whereIn('device_id', $orphanedPairs->pluck('device_id')->unique())
            ->get(['id', 'user_id', 'device_id'])
            ->filter(static fn ($row): bool => $orphanedPairs->has(
                ((int) $row->user_id).':'.trim((string) $row->device_id)
            ))
            ->pluck('id')
            ->whenNotEmpty(static function ($ids): void {
                DB::table('user_device_tokens')->whereIn('id', $ids)->delete();
            });
    }
}
