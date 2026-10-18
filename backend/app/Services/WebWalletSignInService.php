<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;

/**
 * Completes the browser wallet's OAuth hand-off with PKCE (RFC 7636).
 *
 * The wallet never creates or links accounts: it resolves the exact provider
 * identity the app already linked. The completion claim, user lock and
 * receipt write happen inside SocialOAuthAttemptService's claim transaction,
 * the same path the native browser login uses.
 */
final readonly class WebWalletSignInService
{
    public const FAILED_ATTEMPT = 'failed_attempt';

    public const EXPIRED = 'expired';

    public const IN_PROGRESS = 'in_progress';

    public const NOT_LINKED = 'not_linked';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        private SocialAuthProviderRegistry $providers,
        private SocialOAuthAttemptService $attempts,
        private SocialIdentityGuardService $identityGuard
    ) {}

    /** S256 code challenge for a verifier (RFC 7636 section 4.2). */
    public function challenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    /**
     * The signed-in student, or the reason constant the hand-off failed with.
     *
     * @return User|self::FAILED_ATTEMPT|self::EXPIRED|self::IN_PROGRESS|self::NOT_LINKED|self::UNAVAILABLE
     */
    public function complete(?string $completionCode, string $verifier): User|string
    {
        $attempt = $completionCode !== null ? $this->attempts->inspectCompletion($completionCode) : null;
        if (! $attempt || $verifier === ''
            || $attempt->return_to !== $this->providers->webWalletReturnUrl()
            || ! $this->providers->browserAvailable()->contains($attempt->provider)
            || ! hash_equals((string) $attempt->code_challenge, $this->challenge($verifier))) {
            return self::FAILED_ATTEMPT;
        }
        if ($attempt->completion_consumed_at) {
            // A repeated redirect can replay only this browser's PKCE-bound
            // identity. A lost session cookie requires a fresh sign-in.
            try {
                $receipt = json_decode(Crypt::decryptString((string) $attempt->encrypted_session_response), true, 8, JSON_THROW_ON_ERROR);
                $user = User::query()->whereKey($receipt['web_wallet_user_id'] ?? 0)
                    ->where('active', true)->students()->first();

                return $user ?: self::EXPIRED;
            } catch (\Throwable $exception) {
                report($exception);

                return self::EXPIRED;
            }
        }

        $claimed = $this->attempts->claimCompletion((string) $completionCode);
        if (! $claimed) {
            return self::IN_PROGRESS;
        }

        try {
            $identity = $this->providers->verifyIdentity(
                (string) $claimed->provider,
                Crypt::decryptString((string) $claimed->encrypted_token),
                $claimed->nonce_hash
            );
            // Top-up never creates or links accounts. Use the exact provider
            // identity already linked by the app, not a matching email address.
            $user = $this->attempts->whileCompletionClaimIsOwned(
                $claimed->id,
                (string) $claimed->completion_claim_id,
                function () use ($claimed, $identity): ?User {
                    $this->identityGuard->assertLoginStartedAfterLastDeletion(
                        (string) $claimed->provider, $identity['id'], $claimed->created_at
                    );
                    $account = SocialAccount::query()
                        ->where('provider', $claimed->provider)
                        ->where('provider_user_id', $identity['id'])
                        ->first();
                    $user = $account ? User::query()->whereKey($account->user_id)
                        ->where('active', true)->students()->lockForUpdate()->first() : null;
                    $receipt = $user ? Crypt::encryptString(json_encode([
                        'web_wallet_user_id' => (int) $user->id,
                    ], JSON_THROW_ON_ERROR)) : null;
                    if (! $this->attempts->finalizeCompletion($claimed, $receipt)) {
                        return null;
                    }

                    return $user;
                }
            );

            return $user ?: self::NOT_LINKED;
        } catch (\DomainException) {
            $this->attempts->finalizeCompletion($claimed);

            return self::EXPIRED;
        } catch (\Throwable $exception) {
            report($exception);
            $this->attempts->releaseCompletion($claimed);

            return self::UNAVAILABLE;
        }
    }
}
