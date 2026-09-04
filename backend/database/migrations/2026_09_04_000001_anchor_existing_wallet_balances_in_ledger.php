<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotExists(function ($ledger): void {
                $ledger->selectRaw('1')
                    ->from('wallet_transactions')
                    ->whereColumn('wallet_transactions.user_id', 'users.id');
            })
            ->where('wallet_coins', '>', 0)
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $now = now();
                $rows = [];
                foreach ($users as $user) {
                    $total = (int) $user->wallet_coins;
                    $paid = (int) $user->wallet_purchased_coins;
                    $reward = (int) $user->wallet_reward_coins;
                    if ($total < 0 || $paid < 0 || $reward < 0 || $total !== $paid + $reward) {
                        throw new RuntimeException(
                            "Cannot anchor inconsistent wallet projection for user {$user->id}."
                        );
                    }
                    if ($paid !== 0) {
                        throw new RuntimeException(
                            "Cannot invent paid provenance for unledgered wallet user {$user->id}."
                        );
                    }

                    $rows[] = [
                        'public_id' => (string) Str::uuid(),
                        'user_id' => $user->id,
                        'direction' => 'credit',
                        'category' => 'opening_balance',
                        'bucket' => 'reward',
                        'amount' => $total,
                        'paid_amount' => $paid,
                        'reward_amount' => $reward,
                        'balance_after' => $total,
                        'paid_balance_after' => $paid,
                        'reward_balance_after' => $reward,
                        'source_type' => null,
                        'source_id' => null,
                        'idempotency_key' => "wallet-opening:user:{$user->id}",
                        'metadata' => json_encode([
                            'migration' => 'ledger_anchor',
                        ], JSON_THROW_ON_ERROR),
                        'occurred_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows !== []) {
                    DB::table('wallet_transactions')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        DB::table('wallet_transactions')
            ->where('category', 'opening_balance')
            ->where('idempotency_key', 'like', 'wallet-opening:user:%')
            ->delete();
    }
};
