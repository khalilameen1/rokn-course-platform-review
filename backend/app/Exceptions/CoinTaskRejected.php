<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A stable, client-visible reason why a coin task could not be started or
 * claimed. Same shape as CourseCheckoutRejected: callers branch on the reason,
 * never on prose, and the reason is the API error code.
 */
final class CoinTaskRejected extends \DomainException
{
    public const TASK_UNAVAILABLE = 'task_unavailable';

    public const TASK_NOT_STARTED = 'task_not_started';

    public const TASK_QUOTA_REACHED = 'task_quota_reached';

    public const CLAIM_NOT_READY = 'claim_not_ready';

    public const WHATSAPP_NOT_VERIFIED = 'whatsapp_not_verified';

    public const WHATSAPP_BOT_UNAVAILABLE = 'whatsapp_bot_unavailable';

    public const REWARD_BALANCE_FULL = 'reward_balance_full';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }

    /**
     * Collaborators (WhatsApp linking, wallet guards) refuse with a bare
     * DomainException whose message is already a stable code. Keep that code.
     */
    public static function from(\DomainException $exception): self
    {
        return $exception instanceof self
            ? $exception
            : new self($exception->getMessage(), $exception);
    }
}
