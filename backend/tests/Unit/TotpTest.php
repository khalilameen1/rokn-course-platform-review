<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\Totp;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function test_sha1_totp_matches_the_rfc_6238_reference_vector_at_six_digits(): void
    {
        // RFC 6238's SHA-1 test secret is ASCII "12345678901234567890".
        // The RFC's 8-digit value at t=59 is 94287082; its RFC 4226 dynamic
        // truncation at six digits is therefore 287082.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        self::assertSame('287082', (new Totp)->code($secret, 59));
        self::assertSame(1, (new Totp)->matchingStep($secret, '287082', 59, 0));
    }
}
