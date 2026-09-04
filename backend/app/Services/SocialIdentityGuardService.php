<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\SocialIdentityGuard;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class SocialIdentityGuardService
{
    public function assertLoginStartedAfterLastDeletion(
        string $provider,
        string $providerUserId,
        CarbonInterface $attemptStartedAt
    ): void {
        $hash = $this->hash($provider, $providerUserId);
        DB::table('social_identity_guards')->insertOrIgnore([
            'identity_hash' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $guard = SocialIdentityGuard::query()
            ->where('identity_hash', $hash)
            ->lockForUpdate()
            ->firstOrFail();

        if ($guard->deletion_started_at
            && $attemptStartedAt->lessThanOrEqualTo($guard->deletion_started_at)) {
            throw new \DomainException('social_login_predates_account_deletion');
        }
    }

    public function markDeletionStarted(int $userId): void
    {
        $identities = SocialAccount::query()
            ->where('user_id', $userId)
            ->get(['provider', 'provider_user_id'])
            ->map(fn (SocialAccount $account): array => [
                (string) $account->provider,
                (string) $account->provider_user_id,
            ]);
        $legacy = User::withTrashed()->whereKey($userId)->first(['social_provider', 'social_id']);
        if ($legacy && trim((string) $legacy->social_provider) !== '' && trim((string) $legacy->social_id) !== '') {
            $identities->push([(string) $legacy->social_provider, (string) $legacy->social_id]);
        }
        $hashes = $identities
            ->map(fn (array $identity): string => $this->hash($identity[0], $identity[1]))
            ->unique()
            ->sort()
            ->values();
        if ($hashes->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($hashes): void {
            $now = now();
            foreach ($hashes as $hash) {
                DB::table('social_identity_guards')->insertOrIgnore([
                    'identity_hash' => $hash,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            SocialIdentityGuard::query()
                ->whereIn('identity_hash', $hashes->all())
                ->orderBy('identity_hash')
                ->lockForUpdate()
                ->get()
                ->each(static function (SocialIdentityGuard $guard) use ($now): void {
                    $guard->forceFill([
                        'deletion_started_at' => $now,
                        'updated_at' => $now,
                    ])->save();
                });
        }, 3);
    }

    /**
     * Two different providers can return the same verified email at the same
     * time. Lock that linking key before looking up or creating its user so a
     * first login cannot become a transient duplicate-email failure.
     */
    public function lockVerifiedEmailLink(string $email): void
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            throw new \InvalidArgumentException('Verified email is required.');
        }

        $hash = hash_hmac('sha256', 'verified-email|'.$email, (string) config('app.key'));
        DB::table('social_identity_guards')->insertOrIgnore([
            'identity_hash' => $hash,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        SocialIdentityGuard::query()
            ->where('identity_hash', $hash)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function hash(string $provider, string $providerUserId): string
    {
        return hash_hmac(
            'sha256',
            strtolower(trim($provider)).'|'.trim($providerUserId),
            (string) config('app.key')
        );
    }
}
