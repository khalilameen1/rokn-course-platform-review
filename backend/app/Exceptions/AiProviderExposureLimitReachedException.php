<?php

declare(strict_types=1);

namespace App\Exceptions;

/** The account hit its private unknown-provider-outcome safety ceiling. */
final class AiProviderExposureLimitReachedException extends \RuntimeException {}
