<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\SocialProviderUnavailableException;
use App\Support\FacebookGraphVersion;
use Illuminate\Support\Collection;
use RuntimeException;

final class SocialAuthProviderRegistry
{
    /** @var array<string, string> */
    private const LABELS = [
        'google' => 'Google',
        'tiktok' => 'TikTok',
        'apple' => 'Apple',
        'facebook' => 'Facebook',
    ];

    public function __construct(
        private readonly FacebookService $facebook,
        private readonly GoogleService $google,
        private readonly TikTokService $tiktok,
        private readonly AppleService $apple
    ) {
    }

    /** @return Collection<int, string> */
    public function declared(): Collection
    {
        return collect(config('social_auth.providers', []))
            ->map(static fn ($provider): string => strtolower(trim((string) $provider)))
            ->filter(static fn (string $provider): bool => isset(self::LABELS[$provider]))
            ->unique()
            ->values();
    }

    /** @return Collection<int, string> */
    public function available(): Collection
    {
        return $this->declared()
            ->filter(fn (string $provider): bool => $this->isReady($provider))
            ->values();
    }

    /** @return Collection<int, string> */
    public function browserDeclared(): Collection
    {
        return $this->declared()
            ->filter(static fn (string $provider): bool => $provider !== 'apple')
            ->values();
    }

    /** @return Collection<int, string> */
    public function browserAvailable(): Collection
    {
        return $this->available()
            ->filter(static fn (string $provider): bool => $provider !== 'apple')
            ->values();
    }

    /** @return Collection<int, string> */
    public function nativeOnlyAvailable(): Collection
    {
        return $this->available()
            ->filter(static fn (string $provider): bool => $provider === 'apple')
            ->values();
    }

    public function isReady(string $provider): bool
    {
        return match ($provider) {
            'google' => $this->nonBlank(config('services.google.client_id'))
                && $this->nonBlank(config('services.google.client_secret')),
            'facebook' => $this->nonBlank(config('services.facebook.client_id'))
                && $this->nonBlank(config('services.facebook.client_secret'))
                && FacebookGraphVersion::normalize(config('services.facebook.graph_version')) !== null,
            'tiktok' => $this->nonBlank(config('services.tiktok.client_key'))
                && $this->nonBlank(config('services.tiktok.client_secret'))
                && $this->validHttpsEndpoint(config('social_auth.tiktok.user_info_url')),
            'apple' => $this->appleClientIdsAreValid(),
            default => false,
        };
    }

    /** @return array<string, string> */
    public function labels(): array
    {
        return $this->declared()
            ->mapWithKeys(static fn (string $provider): array => [$provider => self::LABELS[$provider]])
            ->all();
    }

    public function reason(string $provider): string
    {
        if ($this->isReady($provider)) {
            return match ($provider) {
                'google' => 'Google OAuth مضبوط',
                'facebook' => 'Facebook OAuth مضبوط على '.trim((string) config('services.facebook.graph_version')),
                'tiktok' => 'TikTok OAuth مضبوط',
                'apple' => 'Apple Sign in audiences مضبوطة',
                default => 'المزوّد مضبوط',
            };
        }

        return match ($provider) {
            'google' => 'GOOGLE_CLIENT_ID أو GOOGLE_CLIENT_SECRET ناقص',
            'facebook' => 'FACEBOOK_CLIENT_ID أو FACEBOOK_CLIENT_SECRET أو FACEBOOK_GRAPH_VERSION ناقص أو غير صالح',
            'tiktok' => 'TIKTOK_CLIENT_KEY أو TIKTOK_CLIENT_SECRET أو TIKTOK_USER_INFO_URL ناقص أو غير صالح',
            'apple' => 'APPLE_CLIENT_ID ناقص أو غير صالح',
            default => 'مزوّد غير مدعوم',
        };
    }

    private function appleClientIdsAreValid(): bool
    {
        $clientIds = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('services.apple.client_id'))
        )));

        return $clientIds !== []
            && collect($clientIds)->every(
                static fn (string $id): bool => preg_match('/\A(?:[A-Za-z0-9-]+\.)+[A-Za-z0-9-]+\z/', $id) === 1
            );
    }

    /**
     * Resolve a provider credential into the one identity shape consumed by
     * account linking. Provider-specific response formats stop here.
     *
     * @return array{id: string, identity_issued_at: ?int, name: ?string, email: ?string, email_verified: bool, picture: ?string}
     */
    public function verifyIdentity(
        string $provider,
        string $credential,
        ?string $expectedNonceHash = null,
        ?string $appleRawNonce = null
    ): array {
        if (!$this->isReady($provider)) {
            throw new SocialProviderUnavailableException('Social provider is not configured.');
        }

        $identity = match ($provider) {
            'facebook' => $this->facebook->verify($credential),
            'google' => $this->google->verify($credential, $expectedNonceHash),
            'tiktok' => $this->tiktok->verify($credential),
            'apple' => $this->apple->verify($credential, (string) $appleRawNonce),
            default => throw new RuntimeException('Unsupported social provider.'),
        };

        $providerUserId = trim((string) ($identity['id'] ?? ''));
        if ($providerUserId === '' || strlen($providerUserId) > 191) {
            throw new RuntimeException('Social provider returned an invalid identity.');
        }

        return [
            'id' => $providerUserId,
            'identity_issued_at' => is_numeric($identity['identity_issued_at'] ?? null)
                ? (int) $identity['identity_issued_at']
                : null,
            'name' => isset($identity['name']) ? (string) $identity['name'] : null,
            'email' => isset($identity['email']) ? (string) $identity['email'] : null,
            'email_verified' => (bool) ($identity['email_verified'] ?? false),
            'picture' => isset($identity['picture']) ? (string) $identity['picture'] : null,
        ];
    }

    public function publicApiUrl(): string
    {
        $configured = rtrim(trim((string) config('social_auth.public_api_url')), '/');

        return $configured !== ''
            ? $configured
            : rtrim(url('/api/v1'), '/');
    }

    public function browserStartUrl(string $provider): string
    {
        if (!$this->browserAvailable()->contains($provider)) {
            throw new RuntimeException('Social provider is not available for browser login.');
        }

        return $this->publicApiUrl().'/social-auth/'.rawurlencode($provider).'/start';
    }

    public function browserCallbackUrl(string $provider): string
    {
        if (!$this->browserDeclared()->contains($provider)) {
            throw new RuntimeException('Social provider does not support browser login.');
        }

        return $this->publicApiUrl().'/social-auth/'.rawurlencode($provider).'/callback';
    }

    private function nonBlank(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    private function validHttpsEndpoint(mixed $value): bool
    {
        $url = trim((string) $value);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== ''
            && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);
    }
}
