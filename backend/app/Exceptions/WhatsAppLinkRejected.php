<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * A verified inbound WhatsApp message that cannot link the sender. Same shape
 * as CoinTaskRejected and CourseCheckoutRejected: callers branch on the stable
 * reason, never on exception text.
 */
final class WhatsAppLinkRejected extends \DomainException
{
    /** The sender's number is already connected to another Rokn account. */
    public const PHONE_IN_USE = 'whatsapp_phone_in_use';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
