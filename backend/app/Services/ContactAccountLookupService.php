<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/** A matching email is a display/workflow lookup, not evidence of account ownership. */
final class ContactAccountLookupService
{
    public function forEmail(?string $email): ?User
    {
        $normalizedEmail = Str::lower(trim((string) $email));
        if ($normalizedEmail === '') {
            return null;
        }

        return User::query()
            ->whereRaw('LOWER(email) = ?', [$normalizedEmail])
            ->first();
    }

}
