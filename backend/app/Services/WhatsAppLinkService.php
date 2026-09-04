<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinEarningMethod;
use App\Models\User;
use App\Models\UserCoinTaskAttempt;
use App\Models\UserWhatsAppConnection;
use App\Models\WhatsAppLinkToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class WhatsAppLinkService
{
    private const TOKEN_PREFIX = 'ROKN_LINK_';

    public function __construct(
        private WalletService $wallet,
        private AcquisitionRewardTombstoneService $rewardTombstones
    ) {
    }

    /** @return array<string, mixed> */
    public function createLink(User $user, CoinEarningMethod $method): array
    {
        if (
            !(bool) config('whatsapp.enabled')
            || !$method->isAvailableNow()
            || !$method->hasClaimCapacity()
            || $method->action_key !== 'link_whatsapp'
        ) {
            throw new \DomainException('task_unavailable');
        }

        $botPhone = $this->normalizeBotPhone((string) config('whatsapp.linking.bot_phone'));
        if ($botPhone === null) {
            throw new \DomainException('whatsapp_bot_unavailable');
        }

        $rawToken = bin2hex(random_bytes(24));
        $expiresAt = now()->addMinutes(max(
            5,
            min(1440, (int) config('whatsapp.linking.token_minutes', 30))
        ));

        $result = DB::transaction(function () use ($user, $method, $rawToken, $expiresAt): array {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            // Starting a link does not consume the campaign quota. The actual
            // inbound claim below is its financial boundary.
            $lockedMethod = CoinEarningMethod::query()->findOrFail($method->id);
            if (!$lockedMethod->isAvailableNow() || !$lockedMethod->hasClaimCapacity()) {
                throw new \DomainException('task_unavailable');
            }
            $earning = $lockedUser->coinEarnings()
                ->where('coin_earning_method_id', $lockedMethod->id)
                ->first();
            $attempt = UserCoinTaskAttempt::query()->firstOrCreate(
                [
                    'user_id' => $lockedUser->id,
                    'coin_earning_method_id' => $lockedMethod->id,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'status' => UserCoinTaskAttempt::STATUS_STARTED,
                    'started_at' => now(),
                    'claim_available_at' => null,
                ]
            );

            if ($earning || $attempt->status === UserCoinTaskAttempt::STATUS_CLAIMED) {
                return ['claimed' => true, 'attempt' => $attempt];
            }

            // Keep earlier unexpired links usable. A rapid second tap can race
            // the already-open WhatsApp composer; invalidating the first link
            // here made the exact message visible to the learner stop working.
            // The user aggregate and wallet idempotency key serialize whichever
            // valid link reaches us first.
            WhatsAppLinkToken::query()->create([
                'user_id' => $lockedUser->id,
                'coin_earning_method_id' => $lockedMethod->id,
                'token_hash' => hash('sha256', $rawToken),
                'expires_at' => $expiresAt,
            ]);

            return ['claimed' => false, 'attempt' => $attempt];
        });

        if ($result['claimed']) {
            return [
                'task_state' => 'claimed',
                'action_url' => null,
                'attempt_id' => $result['attempt']->public_id,
            ];
        }

        $message = 'أرسل هذه الجملة لربط الحساب والحصول على المكافأة '
            . self::TOKEN_PREFIX . $rawToken;

        return [
            'task_state' => 'started',
            'attempt_id' => $result['attempt']->public_id,
            'action_url' => 'https://wa.me/' . $botPhone . '?text=' . rawurlencode($message),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @return array{matched:bool,already_claimed:bool,user:?User,coins:int,earned_coins:int,balance:int,reward_deferred:bool,reward_unavailable:bool} */
    public function consumeInbound(string $sender, string $message): array
    {
        $rawToken = $this->extractToken($message);
        $phone = $this->normalizeSenderPhone($sender);
        if ($rawToken === null || $phone === null) {
            return $this->unmatched();
        }

        return DB::transaction(function () use ($rawToken, $phone): array {
            /** @var WhatsAppLinkToken|null $link */
            $link = WhatsAppLinkToken::query()
                ->where('token_hash', hash('sha256', $rawToken))
                ->lockForUpdate()
                ->first();
            if (!$link || $link->expires_at->isPast()) {
                return $this->unmatched();
            }

            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($link->user_id);
            /** @var CoinEarningMethod $method */
            // A link may arrive after the dashboard retires its campaign.
            // The verified inbound message must still connect the number and
            // consume the one-time token rather than making the webhook retry
            // forever because the method is now soft-deleted.
            $method = CoinEarningMethod::withTrashed()->findOrFail($link->coin_earning_method_id);
            if ($method->total_claim_limit !== null) {
                $method = CoinEarningMethod::withTrashed()
                    ->lockForUpdate()
                    ->findOrFail($method->id);
            }

            if ($link->consumed_at) {
                // consumed_at was also used by older releases to invalidate a
                // superseded link. Only a token carrying the same verified
                // sender is a real inbound replay.
                $linkedPhone = trim((string) $link->sender_phone_e164);
                if ($linkedPhone === '' || !hash_equals($linkedPhone, $phone)) {
                    return $this->unmatched();
                }

                $earning = $user->coinEarnings()
                    ->where('coin_earning_method_id', $method->id)
                    ->first();
                $alreadyClaimed = $earning !== null
                    || UserCoinTaskAttempt::query()
                        ->where('user_id', $user->id)
                        ->where('coin_earning_method_id', $method->id)
                        ->where('status', UserCoinTaskAttempt::STATUS_CLAIMED)
                        ->exists();
                $rewardKey = $this->rewardTombstones->rewardKeyForMethod($method);
                $alreadyClaimed = $alreadyClaimed || ($rewardKey !== null
                    && $this->rewardTombstones->identityHasConsumed('whatsapp', $phone, $rewardKey));

                return [
                    'matched' => true,
                    'already_claimed' => $alreadyClaimed,
                    'user' => $user,
                    'coins' => 0,
                    // Allows the idempotent notification receipt to be repaired
                    // if the first webhook committed the wallet credit but the
                    // HTTP process stopped before enqueueing its push.
                    'earned_coins' => (int) ($earning?->amount ?? 0),
                    'balance' => $this->wallet->balances($user)['total'],
                    'reward_deferred' => false,
                    'reward_unavailable' => false,
                ];
            }

            $connection = UserWhatsAppConnection::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (
                $connection?->verified_at
                && !hash_equals((string) $connection->phone_e164, $phone)
            ) {
                // Multiple composers may be open after a double tap. Once one
                // number has proved ownership, a late sibling token must never
                // silently replace it with the sender of the stale composer.
                $link->forceFill(['consumed_at' => now()])->save();

                return $this->unmatched();
            }

            if (UserWhatsAppConnection::query()
                ->where('phone_e164', $phone)
                ->where('user_id', '!=', $user->id)
                ->exists()) {
                throw new \DomainException('whatsapp_phone_in_use');
            }

            $connection ??= new UserWhatsAppConnection(['user_id' => $user->id]);
            $connection->forceFill([
                'phone_e164' => $phone,
                'declared_at' => $connection->declared_at ?? now(),
                'ownership_verified' => true,
                'verified_at' => $connection->verified_at ?? now(),
                'marketing_opt_in' => (bool) ($connection->marketing_opt_in ?? false),
                'consent_source' => 'whatsapp_link_message',
            ])->save();

            $earning = $user->coinEarnings()
                ->where('coin_earning_method_id', $method->id)
                ->first();
            $attempt = UserCoinTaskAttempt::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'coin_earning_method_id' => $method->id,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'status' => UserCoinTaskAttempt::STATUS_STARTED,
                    'started_at' => now(),
                ]
            );
            $rewardKey = $this->rewardTombstones->rewardKeyForMethod($method);
            $identityAlreadyConsumed = $rewardKey !== null
                && $this->rewardTombstones->identityHasConsumed('whatsapp', $phone, $rewardKey);
            $alreadyClaimed = $earning !== null
                || $attempt->status === UserCoinTaskAttempt::STATUS_CLAIMED
                || $identityAlreadyConsumed;
            $rewardAvailable = $method->isAvailableNow() && $method->hasClaimCapacity();
            $coins = 0;
            $earnedCoins = (int) ($earning?->amount ?? 0);
            $balance = $this->wallet->balances($user)['total'];
            $rewardDeferred = false;

            if (!$alreadyClaimed && $rewardAvailable) {
                $transaction = $this->wallet->creditRewardWithinConfiguredCap(
                    $user->id,
                    (int) $method->coins_amount,
                    'task_reward',
                    "coin-task:{$user->id}:{$method->id}",
                    $method,
                    [
                        'action_key' => $method->action_key,
                        'campaign_key' => $method->campaign_key,
                        'verified_by' => 'whatsapp_inbound',
                        'reward_timezone' => \App\Support\BusinessClock::timezoneName(),
                    ]
                );
                if ($transaction) {
                    $user->coinEarnings()->firstOrCreate(
                        ['coin_earning_method_id' => $method->id],
                        ['amount' => $transaction->amount]
                    );
                    $attempt->forceFill([
                        'status' => UserCoinTaskAttempt::STATUS_CLAIMED,
                        'claim_available_at' => now(),
                        'claimed_at' => now(),
                        'metadata' => array_merge((array) $attempt->metadata, [
                            'verification' => 'whatsapp_inbound',
                        ]),
                    ])->save();
                    $coins = (int) $transaction->amount;
                    $earnedCoins = $coins;
                    $balance = (int) $transaction->balance_after;
                } else {
                    $rewardDeferred = true;
                }
            } elseif ($identityAlreadyConsumed && $attempt->status !== UserCoinTaskAttempt::STATUS_CLAIMED) {
                $attempt->forceFill([
                    'status' => UserCoinTaskAttempt::STATUS_CLAIMED,
                    'claim_available_at' => now(),
                    'claimed_at' => now(),
                    'metadata' => array_merge((array) $attempt->metadata, [
                        'verification' => 'whatsapp_inbound',
                        'reward_suppressed' => 'identity_already_consumed',
                    ]),
                ])->save();
            }

            $link->forceFill([
                'consumed_at' => now(),
                'sender_phone_e164' => $phone,
            ])->save();

            return [
                'matched' => true,
                'already_claimed' => $alreadyClaimed,
                'user' => $user->fresh(),
                'coins' => $coins,
                'earned_coins' => $earnedCoins,
                'balance' => $balance,
                'reward_deferred' => $rewardDeferred,
                'reward_unavailable' => !$alreadyClaimed && !$rewardAvailable,
            ];
        });
    }

    private function extractToken(string $message): ?string
    {
        if (!preg_match('/' . self::TOKEN_PREFIX . '([a-f0-9]{48})/i', $message, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    private function normalizeBotPhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return preg_match('/^[1-9][0-9]{9,14}$/', $digits) ? $digits : null;
    }

    private function normalizeSenderPhone(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?: '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = '20' . substr($digits, 1);
        }

        return preg_match('/^[1-9][0-9]{9,14}$/', $digits) ? '+' . $digits : null;
    }

    /** @return array{matched:false,already_claimed:false,user:null,coins:0,earned_coins:0,balance:0,reward_deferred:false,reward_unavailable:false} */
    private function unmatched(): array
    {
        return [
            'matched' => false,
            'already_claimed' => false,
            'user' => null,
            'coins' => 0,
            'earned_coins' => 0,
            'balance' => 0,
            'reward_deferred' => false,
            'reward_unavailable' => false,
        ];
    }
}
