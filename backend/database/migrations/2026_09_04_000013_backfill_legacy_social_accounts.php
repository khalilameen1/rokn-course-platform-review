<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('users')
            || !Schema::hasTable('social_accounts')
            || !Schema::hasColumn('users', 'social_provider')
            || !Schema::hasColumn('users', 'social_id')
        ) {
            return;
        }

        DB::table('users')
            ->select(['id', 'social_provider', 'social_id', 'email', 'name', 'profile_image'])
            ->whereNotNull('social_provider')
            ->whereNotNull('social_id')
            ->orderBy('id')
            ->chunkById(500, function ($users): void {
                $now = now();
                $rows = $users->map(static function ($user) use ($now): ?array {
                    $provider = strtolower(trim((string) $user->social_provider));
                    $providerUserId = trim((string) $user->social_id);
                    if (
                        !in_array($provider, ['google', 'facebook', 'tiktok', 'apple'], true)
                        || $providerUserId === ''
                        || strlen($providerUserId) > 191
                    ) {
                        return null;
                    }

                    return [
                        'user_id' => (int) $user->id,
                        'provider' => $provider,
                        'provider_user_id' => $providerUserId,
                        'provider_email' => filter_var($user->email, FILTER_VALIDATE_EMAIL)
                            ? strtolower((string) $user->email)
                            : null,
                        'provider_name' => trim((string) $user->name) ?: null,
                        'avatar_url' => $user->profile_image ?: null,
                        'last_verified_at' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                })->filter()->values()->all();

                if ($rows !== []) {
                    // Existing social_accounts rows are authoritative when old
                    // profile columns disagree. Unique constraints also keep a
                    // legacy identity from being attached to two users.
                    DB::table('social_accounts')->insertOrIgnore($rows);
                }
            }, 'id');
    }

    public function down(): void
    {
        // This migration promotes existing identity data; deleting linked
        // identities on rollback would make valid accounts unreachable.
    }
};
