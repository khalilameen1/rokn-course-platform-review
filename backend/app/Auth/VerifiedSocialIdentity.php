<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Str;

/** Provider-verified identity facts; never construct these from raw request input. */
final readonly class VerifiedSocialIdentity
{
    private function __construct(
        public string $provider,
        public string $providerUserId,
        public ?string $email,
        public bool $emailVerified,
        public string $name,
        public ?string $picture,
        public array $appleGrant
    ) {
    }

    /**
     * Consume only SocialAuthProviderRegistry::verifyIdentity output.
     * Apple's separately supplied first-consent name may label an account;
     * it can never establish its email, provider ID or authorization grant.
     */
    public static function fromProvider(
        string $provider,
        array $verified,
        ?string $appleDisplayName = null
    ): self {
        $email = isset($verified['email']) && filter_var($verified['email'], FILTER_VALIDATE_EMAIL)
            ? Str::lower((string) $verified['email'])
            : null;
        $name = trim((string) ($verified['name'] ?? '')) ?: 'طالب ركن';
        if ($provider === 'apple'
            && trim((string) ($verified['name'] ?? '')) === ''
            && trim((string) $appleDisplayName) !== '') {
            $name = trim((string) $appleDisplayName);
        }

        return new self(
            provider: $provider,
            providerUserId: (string) $verified['id'],
            email: $email,
            emailVerified: $email !== null && (bool) ($verified['email_verified'] ?? false),
            name: Str::limit($name, 255, ''),
            picture: isset($verified['picture']) ? (string) $verified['picture'] : null,
            appleGrant: $provider === 'apple' ? $verified['apple_grant'] : [],
        );
    }
}
