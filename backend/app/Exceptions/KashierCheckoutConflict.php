<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Why a Kashier package checkout could not start a new hosted payment. The
 * reason constants are the API error codes the mobile app reads; the English
 * message is diagnostic only and may be reworded freely.
 */
final class KashierCheckoutConflict extends \UnexpectedValueException
{
    public const PENDING_CHECKOUT_EXISTS = 'pending_checkout_exists';
    public const PACKAGE_NOT_AVAILABLE = 'package_not_available';
    public const PACKAGE_TERMS_CHANGED = 'package_terms_changed';
    public const IDEMPOTENCY_CONFLICT = 'checkout_idempotency_conflict';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
