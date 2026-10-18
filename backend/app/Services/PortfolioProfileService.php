<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Support\RoknPublicUrl;
use Illuminate\Support\Facades\DB;

/**
 * The owner's portfolio headline, skills and links, and the sharing state shown
 * beside them. Reading it never writes (RFC 9110 safe method): the share slug
 * is created on registration and portfolio writes, and stored review state is
 * reconciled by the 'portfolio.sharing.reconcile' signal that every content
 * change records (PortfolioModerationService::invalidate).
 */
final readonly class PortfolioProfileService
{
    public function __construct(
        private PortfolioShareIdentityService $portfolioShares,
        private PortfolioReviewReadService $reviews
    ) {}

    /** @return array<string, mixed> */
    public function profile(User $user): array
    {
        $slug = $this->portfolioShares->isValidUnlistedSlug((string) $user->portfolio_slug)
            ? (string) $user->portfolio_slug
            : null;
        $sharing = $this->reviews->ownerState($user);

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
            'public_url' => $sharing['published'] && $slug !== null ? RoknPublicUrl::portfolio($slug) : null,
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
            $this->portfolioShares->ensure($locked);

            return $locked->fresh();
        });

        return $this->profile($updated->fresh());
    }
}
