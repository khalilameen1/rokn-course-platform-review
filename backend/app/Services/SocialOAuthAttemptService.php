<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialOAuthAttempt;
use Closure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SocialOAuthAttemptService
{
    public function begin(
        string $state,
        string $provider,
        string $returnTo,
        string $codeChallenge,
        ?string $nonce = null
    ): SocialOAuthAttempt {
        $attributes = [
            'state_hash' => $this->hash($state),
            'provider' => $provider,
            'return_to' => $returnTo,
            'code_challenge' => $codeChallenge,
            'state_expires_at' => now()->addMinutes(10),
        ];
        $attributes['nonce_hash'] = $nonce !== null ? $this->hash($nonce) : null;

        return SocialOAuthAttempt::query()->create($attributes);
    }

    public function inspectState(string $state): ?SocialOAuthAttempt
    {
        return SocialOAuthAttempt::query()
            ->where('state_hash', $this->hash($state))
            ->whereNull('state_consumed_at')
            ->where('state_expires_at', '>', now())
            ->first();
    }

    /**
     * Resolve only the callback routing/binding of a state that is no longer
     * usable. An expired or already-consumed state must never be reopened, but
     * its terminal redirect still has to reach the mobile attempt that owns it.
     */
    public function inspectKnownState(string $state, string $provider): ?SocialOAuthAttempt
    {
        return SocialOAuthAttempt::query()
            ->where('state_hash', $this->hash($state))
            ->where('provider', $provider)
            ->first();
    }

    public function consumeState(string $state, string $provider): ?SocialOAuthAttempt
    {
        return DB::transaction(function () use ($state, $provider): ?SocialOAuthAttempt {
            $attempt = SocialOAuthAttempt::query()
                ->where('state_hash', $this->hash($state))
                ->whereNull('state_consumed_at')
                ->where('state_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$attempt || !hash_equals((string) $attempt->provider, $provider)) {
                return null;
            }

            $attempt->forceFill(['state_consumed_at' => now()])->save();

            return $attempt;
        }, 3);
    }

    /** End a failed callback without reopening its one-time provider code. */
    public function failState(SocialOAuthAttempt $attempt): void
    {
        SocialOAuthAttempt::query()
            ->whereKey($attempt->id)
            ->whereNotNull('state_consumed_at')
            ->whereNull('completion_hash')
            ->update(['state_expires_at' => now()]);
    }

    public function issueCompletion(
        SocialOAuthAttempt $attempt,
        string $completionCode,
        string $encryptedToken
    ): void {
        $attributes = [
            'completion_hash' => $this->hash($completionCode),
            'encrypted_token' => $encryptedToken,
            // Match the mobile handoff window. PKCE and the one-time hash keep
            // the code constrained while a killed/slow app gets enough time
            // to resume the completed login instead of showing a false expiry.
            'completion_expires_at' => now()->addMinutes(10),
            'completion_processing_at' => null,
            'completion_claim_id' => null,
            'completion_consumed_at' => null,
            'encrypted_session_response' => null,
        ];
        // Retain the opaque handoff code only for this attempt's short
        // lifetime. If the provider retries a successful callback after
        // losing our redirect, the app receives the same PKCE-bound code.
        $attributes['encrypted_completion_code'] = Crypt::encryptString($completionCode);
        $attempt->forceFill($attributes)->save();
    }

    public function inspectCallbackReplay(string $state, string $provider): ?SocialOAuthAttempt
    {
        return SocialOAuthAttempt::query()
            ->where('state_hash', $this->hash($state))
            ->where('provider', $provider)
            ->whereNotNull('state_consumed_at')
            ->whereNotNull('completion_hash')
            ->whereNotNull('encrypted_completion_code')
            ->where('completion_expires_at', '>', now())
            ->first();
    }

    /**
     * A duplicated browser callback can arrive while the first request is
     * outside the database exchanging the provider code. Wait only for that
     * exact claimed state; invalid, cancelled and failed attempts do not
     * consume a PHP worker for the full window.
     */
    public function waitForCallbackReplay(
        string $state,
        string $provider,
        int $seconds
    ): ?SocialOAuthAttempt {
        $deadline = microtime(true) + max(0, min(12, $seconds));
        do {
            $replay = $this->inspectCallbackReplay($state, $provider);
            if ($replay) {
                return $replay;
            }

            $stillProcessing = SocialOAuthAttempt::query()
                ->where('state_hash', $this->hash($state))
                ->where('provider', $provider)
                ->whereNotNull('state_consumed_at')
                ->whereNull('completion_hash')
                ->where('state_expires_at', '>', now())
                ->exists();
            if (!$stillProcessing || microtime(true) >= $deadline) {
                return null;
            }

            usleep(250_000);
        } while (true);
    }

    public function inspectCompletion(string $completionCode): ?SocialOAuthAttempt
    {
        return SocialOAuthAttempt::query()
            ->where('completion_hash', $this->hash($completionCode))
            ->where('completion_expires_at', '>', now())
            ->first();
    }

    public function consumeCompletion(string $completionCode): ?SocialOAuthAttempt
    {
        return DB::transaction(function () use ($completionCode): ?SocialOAuthAttempt {
            $attempt = SocialOAuthAttempt::query()
                ->where('completion_hash', $this->hash($completionCode))
                ->whereNull('completion_consumed_at')
                ->where('completion_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$attempt) return null;

            $attempt->forceFill(['completion_consumed_at' => now()])->save();

            return $attempt;
        }, 3);
    }

    public function claimCompletion(string $completionCode): ?SocialOAuthAttempt
    {
        return DB::transaction(function () use ($completionCode): ?SocialOAuthAttempt {
            $attempt = SocialOAuthAttempt::query()
                ->where('completion_hash', $this->hash($completionCode))
                ->whereNull('completion_consumed_at')
                ->where('completion_expires_at', '>', now())
                ->where(function ($query): void {
                    $query->whereNull('completion_processing_at')
                        ->orWhere('completion_processing_at', '<=', now()->subMinutes(2));
                })
                ->lockForUpdate()
                ->first();

            if (!$attempt) return null;

            $attempt->forceFill([
                'completion_processing_at' => now(),
                'completion_claim_id' => (string) Str::uuid(),
            ])->save();

            return $attempt;
        }, 3);
    }

    public function releaseCompletion(SocialOAuthAttempt $attempt): void
    {
        $claimId = trim((string) $attempt->completion_claim_id);
        if (!$attempt->completion_processing_at || $claimId === '') {
            return;
        }

        SocialOAuthAttempt::query()
            ->whereKey($attempt->id)
            ->whereNull('completion_consumed_at')
            ->where('completion_claim_id', $claimId)
            ->update([
                'completion_processing_at' => null,
                'completion_claim_id' => null,
            ]);
    }

    /**
     * Run the irreversible session write only while this request still owns
     * the completion. The provider verification happens before this lock; the
     * short critical section covers account/session database writes only.
     */
    public function whileCompletionClaimIsOwned(
        int $attemptId,
        string $claimId,
        Closure $callback
    ): mixed {
        $claimId = trim($claimId);
        if ($attemptId <= 0 || !Str::isUuid($claimId)) {
            return null;
        }

        return DB::transaction(function () use ($attemptId, $claimId, $callback): mixed {
            $attempt = SocialOAuthAttempt::query()
                ->whereKey($attemptId)
                ->where('completion_claim_id', $claimId)
                ->whereNull('completion_consumed_at')
                ->where('completion_expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$attempt) {
                return null;
            }

            $attempt->forceFill(['completion_processing_at' => now()])->save();

            return $callback();
        }, 3);
    }

    public function finalizeCompletion(
        SocialOAuthAttempt $attempt,
        ?string $encryptedSessionResponse = null
    ): bool
    {
        return DB::transaction(function () use ($attempt, $encryptedSessionResponse): bool {
            $locked = SocialOAuthAttempt::query()->lockForUpdate()->find($attempt->id);
            if (!$locked || $locked->completion_consumed_at) return false;
            if (
                trim((string) $attempt->completion_claim_id) === ''
                || !hash_equals(
                    (string) $locked->completion_claim_id,
                    (string) $attempt->completion_claim_id
                )
            ) {
                return false;
            }

            $locked->forceFill([
                'completion_processing_at' => null,
                'completion_claim_id' => null,
                'completion_consumed_at' => now(),
                'encrypted_token' => null,
                'encrypted_session_response' => $encryptedSessionResponse,
            ])->save();

            return true;
        }, 3);
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
