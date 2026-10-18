<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinEarningMethod;
use App\Models\DeletedSocialRewardTombstone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class AcquisitionRewardTombstoneService
{
    public const WELCOME_REWARD = 'welcome_bonus';

    /** Persist only consumed acquisition offers, never identity PII. */
    public function rememberConsumedRewards(User $user): void
    {
        if (! Schema::hasTable('deleted_social_reward_tombstones') || ! Schema::hasTable('social_accounts')) {
            throw new RuntimeException('Account reward tombstone storage is not ready.');
        }

        $keys = $this->consumedRewardKeysForDeletedUser((int) $user->id);
        if ($keys === []) {
            return;
        }

        $identities = $this->rewardIdentities($user);

        foreach ($identities as $identity) {
            $provider = $identity['provider'];
            $providerUserId = $identity['identifier'];
            if ($provider === '' || $providerUserId === '') {
                continue;
            }

            $hmac = $this->identityHmac($provider, $providerUserId);
            $existing = DeletedSocialRewardTombstone::query()
                ->where('provider', $provider)
                ->where('identity_hmac', $hmac)
                ->lockForUpdate()
                ->first();
            $merged = array_values(array_unique(array_merge(
                $existing?->consumed_reward_keys ?? [],
                $keys
            )));
            sort($merged, SORT_STRING);

            if ($existing) {
                $existing->forceFill(['consumed_reward_keys' => $merged])->save();
            } else {
                DeletedSocialRewardTombstone::query()->create([
                    'provider' => $provider,
                    'identity_hmac' => $hmac,
                    'consumed_reward_keys' => $merged,
                ]);
            }
        }
    }

    public function userHasConsumed(User $user, string $rewardKey): bool
    {
        return $rewardKey !== '' && in_array($rewardKey, $this->consumedRewardKeys($user), true);
    }

    public function identityHasConsumed(string $provider, string $identifier, string $rewardKey): bool
    {
        $provider = strtolower(trim($provider));
        $identifier = trim($identifier);
        if (
            $provider === ''
            || $identifier === ''
            || $rewardKey === ''
            || ! Schema::hasTable('deleted_social_reward_tombstones')
        ) {
            return false;
        }

        $tombstone = DeletedSocialRewardTombstone::query()
            ->where('provider', $provider)
            ->where('identity_hmac', $this->identityHmac($provider, $identifier))
            ->first();

        return in_array($rewardKey, $tombstone?->consumed_reward_keys ?? [], true);
    }

    /** @return list<string> */
    public function consumedRewardKeys(User $user): array
    {
        if (! Schema::hasTable('deleted_social_reward_tombstones') || ! Schema::hasTable('social_accounts')) {
            return [];
        }

        $keys = collect($this->rewardIdentities($user))
            ->flatMap(function (array $identity): array {
                $provider = $identity['provider'];
                $providerUserId = $identity['identifier'];
                if ($provider === '' || $providerUserId === '') {
                    return [];
                }

                $tombstone = DeletedSocialRewardTombstone::query()
                    ->where('provider', $provider)
                    ->where('identity_hmac', $this->identityHmac($provider, $providerUserId))
                    ->first();

                return $tombstone?->consumed_reward_keys ?? [];
            })
            ->filter(fn ($key): bool => is_string($key) && $key !== '')
            ->unique()
            ->values()
            ->all();

        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * Provider ids stop one deleted identity from replaying an offer. The
     * normalized email closes the same-person cross-provider path (for example
     * Google first, then TikTok) without retaining the email itself.
     *
     * @return list<array{provider:string,identifier:string}>
     */
    private function rewardIdentities(User $user): array
    {
        $identities = DB::table('social_accounts')
            ->where('user_id', $user->id)
            ->get(['provider', 'provider_user_id'])
            ->map(static fn ($identity): array => [
                'provider' => strtolower(trim((string) $identity->provider)),
                'identifier' => trim((string) $identity->provider_user_id),
            ])
            ->filter(static fn (array $identity): bool => $identity['provider'] !== '' && $identity['identifier'] !== ''
            );

        $email = mb_strtolower(trim((string) $user->email));
        if ($user->email_verified_at && $email !== '' && str_contains($email, '@')) {
            $identities->push([
                // Tombstone namespace only; this is not an authentication
                // provider and must never be exposed as a login method.
                'provider' => 'email',
                'identifier' => $email,
            ]);
        }

        if (Schema::hasTable('user_whatsapp_connections')) {
            $whatsAppPhone = trim((string) DB::table('user_whatsapp_connections')
                ->where('user_id', $user->id)
                ->where('ownership_verified', true)
                ->whereNotNull('verified_at')
                ->value('phone_e164'));
            if ($whatsAppPhone !== '') {
                // A verified WhatsApp number is an acquisition identity too.
                // Store only its keyed HMAC so deleting and recreating an
                // account cannot replay the one-time linking reward.
                $identities->push([
                    'provider' => 'whatsapp',
                    'identifier' => $whatsAppPhone,
                ]);
            }
        }

        return $identities
            ->unique(static fn (array $identity): string => $identity['provider']."\0".$identity['identifier']
            )
            ->values()
            ->all();
    }

    public function userHasConsumedMethod(User $user, CoinEarningMethod $method): bool
    {
        if ((bool) $method->is_repeatable) {
            return false;
        }

        $key = $this->rewardKeyForMethod($method);

        return $key !== null && $this->userHasConsumed($user, $key);
    }

    public function rewardKeyForMethod(CoinEarningMethod $method): ?string
    {
        return (bool) $method->is_repeatable
            ? null
            : $this->methodRewardKey(
                (string) $method->action_key,
                (int) $method->id,
                (string) ($method->campaign_key ?? '')
            );
    }

    private function consumedRewardKeysForDeletedUser(int $userId): array
    {
        $keys = [];
        if (Schema::hasTable('wallet_transactions') && DB::table('wallet_transactions')
            ->where('user_id', $userId)
            ->where('category', self::WELCOME_REWARD)
            ->exists()) {
            $keys[] = self::WELCOME_REWARD;
        }

        if (Schema::hasTable('user_coin_earnings') && Schema::hasTable('coin_earning_methods')) {
            $columns = ['methods.id as method_id', 'methods.action_key'];
            if (Schema::hasColumn('coin_earning_methods', 'campaign_key')) {
                $columns[] = 'methods.campaign_key';
            }
            $methods = DB::table('user_coin_earnings as earnings')
                ->join('coin_earning_methods as methods', 'methods.id', '=', 'earnings.coin_earning_method_id')
                ->where('earnings.user_id', $userId)
                ->where('methods.is_repeatable', false)
                ->get($columns);
            foreach ($methods as $method) {
                $key = $this->methodRewardKey(
                    (string) $method->action_key,
                    (int) $method->method_id,
                    (string) ($method->campaign_key ?? '')
                );
                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        }

        if (Schema::hasTable('user_coin_task_attempts') && Schema::hasTable('coin_earning_methods')) {
            $columns = ['methods.id as method_id', 'methods.action_key'];
            if (Schema::hasColumn('coin_earning_methods', 'campaign_key')) {
                $columns[] = 'methods.campaign_key';
            }
            $claimedMethods = DB::table('user_coin_task_attempts as attempts')
                ->join('coin_earning_methods as methods', 'methods.id', '=', 'attempts.coin_earning_method_id')
                ->where('attempts.user_id', $userId)
                ->where('attempts.status', 'claimed')
                ->where('methods.is_repeatable', false)
                ->get($columns);
            foreach ($claimedMethods as $method) {
                $key = $this->methodRewardKey(
                    (string) $method->action_key,
                    (int) $method->method_id,
                    (string) ($method->campaign_key ?? '')
                );
                if ($key !== null) {
                    $keys[] = $key;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function methodRewardKey(
        string $actionKey,
        int $methodId,
        string $campaignKey = ''
    ): ?string {
        $actionKey = strtolower(trim($actionKey));
        $campaignKey = strtolower(trim($campaignKey));
        if ($actionKey === 'register') {
            return self::WELCOME_REWARD;
        }
        if ($campaignKey !== '') {
            return 'campaign:'.$campaignKey;
        }
        if ($actionKey === '') {
            return $methodId > 0 ? 'method:'.$methodId : null;
        }

        return 'task:'.$actionKey;
    }

    private function identityHmac(string $provider, string $providerUserId): string
    {
        $key = trim((string) (config('social_auth.reward_tombstone_hmac_key') ?: config('app.key')));
        if ($key === '') {
            throw new RuntimeException('Reward tombstone HMAC key is not configured.');
        }

        return hash_hmac('sha256', $provider."\0".$providerUserId, $key);
    }
}
