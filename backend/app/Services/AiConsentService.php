<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AiConsentService
{
    public const VERSION = 'third-party-ai-v1';
    public const REQUIRED = 'ai_consent_required';
    public const REQUIRED_MESSAGE = 'لاستخدام الاستفسارات ومراجعة المشاريع، حدّث التطبيق ووافق على مشاركة ما ترسله مع خدمة الذكاء الاصطناعي';

    public function accepted(int $userId): bool
    {
        return User::query()->whereKey($userId)->where('active', true)
            ->where('ai_consent_version', self::VERSION)
            ->whereNotNull('ai_consent_accepted_at')->exists();
    }

    public function payload(User $user): array
    {
        $accepted = $this->accepted((int) $user->id);
        return [
            'version' => self::VERSION,
            'accepted' => $accepted,
            'accepted_at' => $accepted ? $user->fresh()->getRawOriginal('ai_consent_accepted_at') : null,
        ];
    }

    public function record(User $user, bool $accepted): array
    {
        // The same user lock orders consent changes against the provider-start
        // boundary. No device, guest or other account can donate its consent.
        DB::transaction(function () use ($user, $accepted): void {
            $locked = User::query()->whereKey($user->id)->where('active', true)->lockForUpdate()->firstOrFail();
            $locked->forceFill([
                'ai_consent_version' => $accepted ? self::VERSION : null,
                'ai_consent_accepted_at' => $accepted
                    ? ($locked->ai_consent_version === self::VERSION && $locked->ai_consent_accepted_at
                        ? $locked->ai_consent_accepted_at : now())
                    : null,
            ])->save();
        }, 3);
        return $this->payload($user);
    }
}
