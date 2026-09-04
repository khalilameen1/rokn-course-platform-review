<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class PortfolioOperationException extends RuntimeException
{
    public const IDENTITY_CONFLICT = 'identity_conflict';
    public const ITEM_UNAVAILABLE = 'item_unavailable';
    public const UPLOAD_FAILED = 'upload_failed';
    public const UPLOAD_EXPIRED = 'upload_expired';
    public const INCOMPLETE_ITEM = 'incomplete_item';
    public const MEDIA_NOT_READY = 'media_not_ready';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
