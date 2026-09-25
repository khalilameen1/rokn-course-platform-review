<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;

/** Either a completed native login or its explicit refusal, without HTTP rendering. */
final readonly class SocialLoginResult
{
    private function __construct(
        public int $status,
        public string $message,
        public ?string $code = null,
        public ?User $user = null,
        public ?string $provider = null,
        public ?string $apiToken = null,
        public ?string $deviceToken = null,
        public int $welcomeBonusGranted = 0
    ) {}

    public static function rejected(int $status, string $code, string $message): self
    {
        return new self(status: $status, message: $message, code: $code);
    }

    public static function authenticated(
        User $user,
        string $provider,
        #[\SensitiveParameter] string $apiToken,
        ?string $deviceToken,
        int $welcomeBonusGranted
    ): self {
        return new self(
            status: 200,
            message: 'تم تسجيل الدخول بنجاح',
            user: $user,
            provider: $provider,
            apiToken: $apiToken,
            deviceToken: $deviceToken,
            welcomeBonusGranted: $welcomeBonusGranted
        );
    }

    public function isSuccessful(): bool
    {
        return $this->user !== null;
    }
}
