<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\RoknPublicUrl;
use Illuminate\Support\Facades\DB;

/** The owner's portfolio headline, skills and links, and the sharing state shown beside them. */
final readonly class PortfolioProfileService
{
    public function __construct(
        private PortfolioShareIdentityService $portfolioShares,
        private PortfolioModerationService $moderation
    ) {}

    /** @return array<string, mixed> */
    public function profile(User $user): array
    {
        $slug = $this->portfolioShares->ensure($user);
        $sharing = $this->moderation->reconcileOwnerState($user);

        return [
            'slug' => $slug,
            'share_mode' => 'unlisted',
            ...$sharing,
            'headline' => $user->portfolio_headline,
            'skills' => $user->portfolio_skills ?? [],
            'links' => collect($user->portfolio_links ?? [])
                ->map(function ($link): ?array {
                    $safeUrl = SafeExternalUrl::sanitize($link['url'] ?? null);
                    if (! $safeUrl) {
                        return null;
                    }

                    return [
                        'label' => (string) ($link['label'] ?? ''),
                        'url' => $safeUrl,
                    ];
                })
                ->filter()
                ->values()
                ->all(),
            // Live whenever an approved version is published, including while
            // newer changes wait for review (see has_unpublished_changes).
            'public_url' => $sharing['published'] ? RoknPublicUrl::portfolio($slug) : null,
        ];
    }

    /**
     * @param  array{portfolio_headline?: ?string, portfolio_skills?: ?list<string>, portfolio_links?: ?list<array{label: string, url: string}>}  $validated
     * @return array<string, mixed>
     */
    public function update(User $user, array $validated): array
    {
        $updated = DB::transaction(function () use ($user, $validated): User {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->update($validated);

            return $locked->fresh();
        });

        return $this->profile($updated->fresh());
    }
}
