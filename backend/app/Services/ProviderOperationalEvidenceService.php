<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ProviderOperationalEvidenceService
{
    /** @return list<array{key:string,label:string,last_success_at:?Carbon}> */
    public function report(): array
    {
        return [
            $this->item('bunny', 'Bunny', 'lesson_media_states', 'last_probe_at', static fn (Builder $query): Builder => $query->where('status', 'ready')),
            $this->item('kashier', 'Kashier', 'orders', 'approved_at', static fn (Builder $query): Builder => $query->where('payment_method', 'kashier')->where('status', 'approved')),
            $this->item('google_play', 'Google Play', 'store_purchases', 'verified_at', static fn (Builder $query): Builder => $query->where('provider', 'google')),
            $this->item('app_store', 'App Store', 'store_purchases', 'verified_at', static fn (Builder $query): Builder => $query->where('provider', 'apple')),
            $this->item('openrouter', 'Rokn AI', 'ai_usage_events', 'completed_at', static fn (Builder $query): Builder => $query->where('status', 'completed')),
            $this->item('firebase', 'Firebase Push', 'student_notifications', 'push_sent_at'),
            ...collect(['google', 'facebook', 'tiktok', 'apple'])
                ->map(fn (string $provider): array => $this->item(
                    'social_'.$provider,
                    'تسجيل '.match ($provider) {
                        'google' => 'Google',
                        'facebook' => 'Facebook',
                        'tiktok' => 'TikTok',
                        default => 'Apple',
                    },
                    'social_accounts',
                    'last_verified_at',
                    static fn (Builder $query): Builder => $query->where('provider', $provider)
                ))
                ->all(),
        ];
    }

    /** @param null|callable(Builder):Builder $scope */
    private function item(
        string $key,
        string $label,
        string $table,
        string $timestamp,
        ?callable $scope = null
    ): array {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $timestamp)) {
            return ['key' => $key, 'label' => $label, 'last_success_at' => null];
        }

        $query = DB::table($table)->whereNotNull($timestamp);
        if ($scope) {
            $query = $scope($query);
        }
        $value = $query->max($timestamp);

        return [
            'key' => $key,
            'label' => $label,
            'last_success_at' => $value ? Carbon::parse($value) : null,
        ];
    }
}
