<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The wallet ledger already makes reward credit idempotent. Collapse
        // any historical duplicate audit receipts before making the matching
        // one-task-per-user invariant explicit at the database boundary too.
        DB::table('user_coin_earnings')
            ->selectRaw('user_id, coin_earning_method_id, MIN(id) as keep_id')
            ->groupBy('user_id', 'coin_earning_method_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('keep_id')
            ->get()
            ->each(function ($duplicate): void {
                DB::table('user_coin_earnings')
                    ->where('user_id', $duplicate->user_id)
                    ->where('coin_earning_method_id', $duplicate->coin_earning_method_id)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->delete();
            });

        Schema::table('user_coin_earnings', function (Blueprint $table): void {
            $table->unique(
                ['user_id', 'coin_earning_method_id'],
                'coin_earning_once_per_user'
            );
        });
    }

    public function down(): void
    {
        Schema::table('user_coin_earnings', function (Blueprint $table): void {
            $table->dropUnique('coin_earning_once_per_user');
        });
    }
};
