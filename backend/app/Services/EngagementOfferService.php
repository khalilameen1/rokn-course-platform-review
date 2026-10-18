<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseCheckout;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Chooses the one coin-task offer to show a learner, generically or as a
 * purchase exit for a specific course plan. A pure read: no checkout, order,
 * debit or enrollment is created.
 */
final readonly class EngagementOfferService
{
    public const NONE = 'none';

    public const PAUSED = 'paused';

    public const OFFER = 'offer';

    public function __construct(
        private EngagementMessageService $messages,
        private EngagementTaskReadService $tasks,
        private WalletService $wallet,
        private CourseCheckoutQuoteService $quotes
    ) {}

    /**
     * @param  array{course_id?: int|string, access_plan_code?: string}|null  $purchaseContext
     * @return array{state: string, data: array<string, mixed>|null}
     */
    public function next(User $user, ?array $purchaseContext): array
    {
        // One snapshot for the wallet balance, the pending-checkout check, the
        // quote and the task catalogue, so the offer never mixes two states.
        $candidate = DB::transaction(function () use ($user, $purchaseContext): ?array {
            if (! $user->active) {
                return null;
            }
            $terms = null;
            if ($purchaseContext !== null) {
                if (! NotificationDeliveryPolicy::allowsInbox($user, 'coin_offer')) {
                    return null;
                }
                // Never turn payment recovery into a reward-task detour.
                if (CourseCheckout::query()->where('user_id', $user->id)->awaitingSettlement()->exists()) {
                    return null;
                }
                try {
                    // Read projection, not CourseCheckoutService::create.
                    $terms = $this->quotes->calculate($user, [
                        'course_id' => (int) $purchaseContext['course_id'],
                        'access_plan_code' => $purchaseContext['access_plan_code'],
                        'mode' => 'purchase', 'channel' => 'direct',
                    ], false, false);
                } catch (\DomainException|ModelNotFoundException|ValidationException) {
                    return null;
                }
                if ($terms['reward_opportunity_coins'] <= 0) {
                    return null;
                }
            }
            $balances = $terms['wallet'] ?? $this->wallet->balances($user);
            $method = $this->tasks->next($user, $this->wallet->rewardCreditRoom((int) $balances['reward']));

            return $method ? ['method' => $method, 'terms' => $terms] : null;
        });
        if (! $candidate) {
            return ['state' => self::NONE, 'data' => null];
        }
        $method = $candidate['method'];
        $message = $this->messages->publicMessage('coin_offer', [
            'task' => $method->learnerTitleAr(), 'coins' => (int) $method->coins_amount,
        ]);
        if (! $message) {
            return ['state' => self::PAUSED, 'data' => null];
        }
        $terms = $candidate['terms'];

        return ['state' => self::OFFER, 'data' => array_replace($message, [
            'campaign_key' => 'coin-offer:'.$method->id,
            'task_id' => (string) $method->id,
            'action_key' => (string) $method->action_key,
            'link' => '/wallet',
            ...($terms ? ['purchase_exit' => [
                'course_id' => (string) $terms['course_id'],
                'access_plan_code' => (string) $terms['access_plan_code'],
                // Full task credit must fit the wallet; only this portion
                // can reduce this course's shortfall. Never promise cash.
                'additional_discount_coins' => min((int) $method->coins_amount, $terms['reward_opportunity_coins']),
            ]] : []),
        ])];
    }
}
