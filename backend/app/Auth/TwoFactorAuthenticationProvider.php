<?php

declare(strict_types=1);

namespace App\Auth;

use PragmaRX\Google2FA\Exceptions\Google2FAException;
use PragmaRX\Google2FA\Google2FA;

/**
 * Dashboard TOTP through pragmarx/google2fa, the engine Laravel Fortify ships.
 *
 * Shaped after Fortify's TwoFactorAuthenticationProvider (generateSecretKey,
 * qrCodeUrl, verify with a configurable window). Rokn's one difference: verify()
 * returns the matched time step and takes the step last accepted for the
 * account, because AdminMfaService records one-time use on the user row
 * under a lock instead of Fortify's cache entry.
 *
 * Secrets are 32 Base32 characters (RFC 4648, no padding), the same shape the
 * previous in-house generator produced, so enrolments made before this class
 * keep verifying.
 */
final readonly class TwoFactorAuthenticationProvider
{
    public function __construct(private Google2FA $engine) {}

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function qrCodeUrl(string $companyName, string $companyEmail, string $secret): string
    {
        return $this->engine->getQRCodeUrl($companyName, $companyEmail, $secret);
    }

    /**
     * Find the time step whose code matches, within the configured window.
     *
     * @param  int|null  $lastUsedStep  step already consumed for this secret; a match at or before it is refused
     * @param  int|null  $timestamp  Unix time to verify against; null means now
     * @return int|null the matched step, or null when nothing in the window matches
     *
     * @throws Google2FAException when the secret is not a usable Base32 key
     */
    public function verify(string $secret, string $code, ?int $lastUsedStep = null, ?int $timestamp = null): ?int
    {
        $code = trim($code);
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) {
            return null;
        }

        $this->engine->setWindow(max(0, (int) config('admin_security.totp_window', 1)));
        $step = $timestamp === null ? null : intdiv($timestamp, $this->engine->getKeyRegeneration());

        // With a previous step the engine starts after it and reports the
        // matched step; -1 keeps that behaviour for a first-ever code.
        $matched = $this->engine->verifyKeyNewer($secret, $code, $lastUsedStep ?? -1, null, $step);

        return is_int($matched) ? $matched : null;
    }

    /**
     * Rokn recovery codes: twelve Base32 characters shown as XXXX-XXXX-XXXX.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(?int $count = null): array
    {
        $count ??= max(1, (int) config('admin_security.recovery_code_count', 10));

        $codes = [];
        for ($index = 0; $index < $count; $index++) {
            $value = substr($this->engine->toBase32(random_bytes(8)), 0, 12);
            $codes[] = implode('-', str_split($value, 4));
        }

        return $codes;
    }
}
