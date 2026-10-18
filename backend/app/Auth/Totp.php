<?php

declare(strict_types=1);

namespace App\Auth;

use InvalidArgumentException;

/**
 * Small RFC 4226 / RFC 6238 implementation used to avoid coupling the admin
 * authentication boundary to a UI package. Secrets remain encrypted by the
 * User model cast; recovery codes are stored only as keyed hashes.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    private const PERIOD_SECONDS = 30;

    private const DIGITS = 6;

    public function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16) {
            throw new InvalidArgumentException('A TOTP secret must contain at least 128 bits.');
        }

        return $this->base32Encode(random_bytes($bytes));
    }

    public function code(string $secret, ?int $timestamp = null): string
    {
        return $this->codeForStep($secret, $this->step($timestamp));
    }

    public function matchingStep(
        string $secret,
        string $code,
        ?int $timestamp = null,
        int $window = 1
    ): ?int {
        $code = trim($code);
        if (preg_match('/^[0-9]{'.self::DIGITS.'}$/D', $code) !== 1) {
            return null;
        }

        $currentStep = $this->step($timestamp);
        foreach (range(-$window, $window) as $offset) {
            $candidateStep = $currentStep + $offset;
            if ($candidateStep >= 0 && hash_equals($this->codeForStep($secret, $candidateStep), $code)) {
                return $candidateStep;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(?int $count = null): array
    {
        $count ??= max(1, (int) config('admin_security.recovery_code_count', 10));

        $codes = [];
        for ($index = 0; $index < $count; $index++) {
            $value = substr($this->base32Encode(random_bytes(8)), 0, 12);
            $codes[] = implode('-', str_split($value, 4));
        }

        return $codes;
    }

    public function hashRecoveryCode(string $code): string
    {
        return hash_hmac('sha256', $this->normalizeRecoveryCode($code), (string) config('app.key'));
    }

    public function secretFingerprint(string $secret): string
    {
        return hash_hmac('sha256', 'admin-mfa:'.$secret, (string) config('app.key'));
    }

    public function otpauthUri(string $secret, string $account): string
    {
        $issuer = trim((string) config('admin_security.issuer', 'Rokn')) ?: 'Rokn';
        $label = rawurlencode($issuer.':'.$account);

        return 'otpauth://totp/'.$label.'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD_SECONDS,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function step(?int $timestamp): int
    {
        return intdiv($timestamp ?? time(), self::PERIOD_SECONDS);
    }

    private function codeForStep(string $secret, int $step): string
    {
        $key = $this->base32Decode($secret);
        $counter = pack('N2', intdiv($step, 4294967296), $step % 4294967296);
        $digest = hash_hmac('sha1', $counter, $key, true);
        $offset = ord($digest[strlen($digest) - 1]) & 0x0F;
        $binary = (
            ((ord($digest[$offset]) & 0x7F) << 24)
            | ((ord($digest[$offset + 1]) & 0xFF) << 16)
            | ((ord($digest[$offset + 2]) & 0xFF) << 8)
            | (ord($digest[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($code)) ?? '');
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $encoded .= self::ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[\s=-]+/', '', $encoded) ?? '');
        if ($encoded === '' || preg_match('/^[A-Z2-7]+$/D', $encoded) !== 1) {
            throw new InvalidArgumentException('Invalid TOTP secret encoding.');
        }

        $bits = '';
        foreach (str_split($encoded) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if ($position === false) {
                throw new InvalidArgumentException('Invalid TOTP secret encoding.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }
}
