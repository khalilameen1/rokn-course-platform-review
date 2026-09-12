<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StoreBillingAccountIdentity
{
    public function google(User|int $user): string
    {
        return hash_hmac('sha256', 'google:user:' . $this->id($user), $this->key());
    }

    public function rememberGoogle(User $user): string
    {
        $binding = $this->google($user);
        DB::table('store_billing_accounts')->insertOrIgnore([
            'google_account_binding' => $binding,
            'user_id' => $user->getKey(),
            'created_at' => now(),
        ]);

        return $binding;
    }

    public function googleUser(string $binding): ?User
    {
        $userId = DB::table('store_billing_accounts')
            ->where('google_account_binding', $binding)
            ->value('user_id');

        return $userId ? User::withTrashed()->find($userId) : null;
    }

    public function apple(User|int $user): string
    {
        $hex = substr(
            hash_hmac('sha256', 'apple:user:' . $this->id($user), $this->key()),
            0,
            32
        );
        $hex[12] = '5';
        $hex[16] = dechex(8 | (hexdec($hex[16]) & 3));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function id(User|int $user): int
    {
        return $user instanceof User ? (int) $user->getKey() : $user;
    }

    private function key(): string
    {
        $key = trim((string) config('store_billing.account_binding_key'));
        if ($key === '') {
            throw new \RuntimeException('Store billing account binding key is not configured.');
        }

        return $key;
    }
}
