<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\SocialOAuthAttempt;
use Carbon\CarbonImmutable;

/** A credential plus its server-owned verification context, never a request bag. */
final readonly class SocialLoginCredentials
{
    private function __construct(
        public string $provider,
        #[\SensitiveParameter] public string $credential,
        public ?string $displayName = null,
        #[\SensitiveParameter] public ?string $appleNonce = null,
        #[\SensitiveParameter] public ?string $appleAuthorizationCode = null,
        public ?CarbonImmutable $attemptStartedAt = null,
        public ?string $expectedNonceHash = null,
        public ?int $attemptId = null,
        public ?string $completionClaimId = null
    ) {}

    public static function native(
        string $provider,
        #[\SensitiveParameter] string $credential,
        ?string $displayName = null,
        #[\SensitiveParameter] ?string $appleNonce = null,
        #[\SensitiveParameter] ?string $appleAuthorizationCode = null
    ): self {
        return new self($provider, $credential, $displayName, $appleNonce, $appleAuthorizationCode);
    }

    /**
     * Only the browser completion boundary, after PKCE and claim acquisition,
     * may supply this model. Copy its facts: later model mutation is not authority.
     * The claim is rechecked under the issuance transaction before any bearer write.
     */
    public static function fromClaimedAttempt(
        SocialOAuthAttempt $attempt,
        #[\SensitiveParameter] string $credential
    ): self {
        if (!$attempt->exists || !$attempt->created_at || !$attempt->completion_claim_id) {
            throw new \LogicException('Browser login requires a persisted completion claim.');
        }

        return new self(
            provider: (string) $attempt->provider,
            credential: $credential,
            attemptStartedAt: CarbonImmutable::instance($attempt->created_at),
            expectedNonceHash: $attempt->nonce_hash,
            attemptId: (int) $attempt->id,
            completionClaimId: (string) $attempt->completion_claim_id,
        );
    }

    public function isBrowserAttempt(): bool
    {
        return $this->attemptId !== null;
    }
}
