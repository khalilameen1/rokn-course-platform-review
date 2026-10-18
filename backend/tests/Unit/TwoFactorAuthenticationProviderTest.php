<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Auth\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Exceptions\IncompatibleWithGoogleAuthenticatorException;
use Tests\TestCase;

final class TwoFactorAuthenticationProviderTest extends TestCase
{
    /**
     * RFC 6238's SHA-1 test secret is ASCII "12345678901234567890". The RFC's
     * 8-digit value at t=59 is 94287082; the retired in-house implementation
     * produced its 6-digit truncation 287082 from this same Base32 secret, so
     * the vector doubles as the compatibility proof for secrets stored before
     * the swap to pragmarx/google2fa.
     */
    private const RFC_6238_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_code_produced_by_the_previous_implementation_matches_the_stored_secret(): void
    {
        $provider = app(TwoFactorAuthenticationProvider::class);

        self::assertSame(1, $provider->verify(self::RFC_6238_SECRET, '287082', null, 59));
        self::assertSame(1, $provider->verify(self::RFC_6238_SECRET, ' 287082 ', null, 59));
    }

    public function test_a_step_at_or_before_the_last_used_one_is_refused(): void
    {
        $provider = app(TwoFactorAuthenticationProvider::class);

        self::assertNull($provider->verify(self::RFC_6238_SECRET, '287082', 1, 59));
        self::assertNull($provider->verify(self::RFC_6238_SECRET, '287082', 5, 59));
        self::assertSame(1, $provider->verify(self::RFC_6238_SECRET, '287082', 0, 59));
    }

    public function test_window_accepts_adjacent_steps_only(): void
    {
        $provider = app(TwoFactorAuthenticationProvider::class);

        self::assertSame(1, $provider->verify(self::RFC_6238_SECRET, '287082', null, 89), 'one step late');
        self::assertSame(1, $provider->verify(self::RFC_6238_SECRET, '287082', null, 29), 'one step early');
        self::assertNull($provider->verify(self::RFC_6238_SECRET, '287082', null, 119), 'two steps late');

        config(['admin_security.totp_window' => 0]);
        self::assertNull($provider->verify(self::RFC_6238_SECRET, '287082', null, 89));
        self::assertSame(1, $provider->verify(self::RFC_6238_SECRET, '287082', null, 59));
    }

    public function test_malformed_codes_never_match(): void
    {
        $provider = app(TwoFactorAuthenticationProvider::class);

        self::assertNull($provider->verify(self::RFC_6238_SECRET, '28708', null, 59));
        self::assertNull($provider->verify(self::RFC_6238_SECRET, '2870821', null, 59));
        self::assertNull($provider->verify(self::RFC_6238_SECRET, '28708a', null, 59));
        self::assertNull($provider->verify(self::RFC_6238_SECRET, '000000', null, 59));
    }

    public function test_new_secrets_keep_the_shape_existing_enrolments_use(): void
    {
        $provider = app(TwoFactorAuthenticationProvider::class);
        $secret = $provider->generateSecretKey();

        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $secret);
        self::assertStringContainsString(
            'otpauth://totp/Rokn:admin%40rokn.test?secret='.$secret.'&issuer=Rokn&algorithm=SHA1&digits=6&period=30',
            $provider->qrCodeUrl('Rokn', 'admin@rokn.test', $secret)
        );
    }

    public function test_recovery_codes_follow_the_grouped_base32_format(): void
    {
        $codes = app(TwoFactorAuthenticationProvider::class)->generateRecoveryCodes();

        self::assertCount(10, $codes);
        self::assertCount(10, array_unique($codes));
        foreach ($codes as $code) {
            self::assertMatchesRegularExpression('/^[A-Z2-7]{4}-[A-Z2-7]{4}-[A-Z2-7]{4}$/D', $code);
        }
    }

    public function test_unusable_secret_surfaces_as_an_exception_for_the_caller_to_report(): void
    {
        $this->expectException(IncompatibleWithGoogleAuthenticatorException::class);

        app(TwoFactorAuthenticationProvider::class)->verify('NOTBASE32', '287082', null, 59);
    }
}
