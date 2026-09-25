<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use App\Support\RoknPublicUrl;
use Illuminate\Support\Str;

final class PublicPortfolioService
{
    public function __construct(
        private readonly PortfolioShareIdentityService $shareIdentity,
        private readonly PortfolioModerationService $moderation,
        private readonly PortfolioReviewReadService $reviews
    ) {
    }

    public function find(
        string $slug,
        ?int $projectPage = null,
        ?int $projectsPerPage = null
    ): ?array
    {
        $user = $this->userForSlug($slug);
        if (!$user) {
            return null;
        }

        $snapshot = $this->reviews->snapshot($user);
        if ($this->moderation->reconcile($user, $snapshot) !== 'approved') return null;

        return $this->fullPortfolio($snapshot, $slug, $projectPage, $projectsPerPage);
    }

    public function mediaForPortfolio(string $slug, string $mediaPublicId, string $revision = '', string $hash = ''): ?PortfolioMedia
    {
        $user = $this->userForSlug($slug);
        if (!$user) return null;
        $snapshot = $this->reviews->snapshot($user);
        if ($this->moderation->reconcile($user, $snapshot) !== 'approved'
            || !$this->matchesSnapshot($snapshot, $revision, $hash)) return null;

        return $this->mediaFromSnapshot($snapshot, $mediaPublicId);
    }

    /** Only the admin-only preview controller may call this suspension bypass. */
    public function adminPreview(User $user): array
    {
        $user = $user->fresh();
        $status = $this->moderation->reconcile($user);
        $snapshot = $this->reviews->snapshot($user);
        return $this->fullPortfolio($snapshot, (string) $user->portfolio_slug, null, null, true) + [
            'review' => [
                'status' => $status,
                'revision' => $snapshot['revision'],
                'snapshot_hash' => $snapshot['hash'],
                'rejection_reason' => $user->portfolio_sharing_rejection_reason,
            ],
        ];
    }

    /** Returns only published work, never the learner's private drafts. */
    public function adminPreviewMedia(User $user, string $mediaPublicId, string $revision = '', string $hash = ''): ?PortfolioMedia
    {
        $snapshot = $this->reviews->snapshot($user->fresh());
        return $this->matchesSnapshot($snapshot, $revision, $hash)
            ? $this->mediaFromSnapshot($snapshot, $mediaPublicId) : null;
    }

    private function matchesSnapshot(array $snapshot, string $revision, string $hash): bool
    {
        return (string) $snapshot['revision'] === $revision && hash_equals($snapshot['hash'], $hash);
    }

    private function mediaFromSnapshot(array $snapshot, string $mediaPublicId): ?PortfolioMedia
    {
        if (!Str::isUuid($mediaPublicId)) {
            return null;
        }

        return $snapshot['items']->flatMap(fn (PortfolioItem $item) => $item->mediaFiles)
            ->first(fn (PortfolioMedia $media) => $media->public_id === $mediaPublicId);
    }

    private function userForSlug(string $slug): ?User
    {
        $slug = trim($slug);
        if (
            $slug === ''
            || strlen($slug) > 100
            || !$this->shareIdentity->isValidUnlistedSlug($slug)
        ) {
            return null;
        }

        $user = User::query()->where('portfolio_slug', $slug)
            ->whereNull('portfolio_sharing_suspended_at')->where('active', true)->first();
        if (!$user) {
            return null;
        }

        return $user;
    }

    private function fullPortfolio(
        array $snapshot,
        string $slug,
        ?int $projectPage,
        ?int $projectsPerPage,
        bool $adminPreview = false
    ): array
    {
        $user = $snapshot['user'];
        $items = $snapshot['items'];
        $projectPagination = null;
        if ($projectPage !== null || $projectsPerPage !== null) {
            $perPage = max(1, min(100, $projectsPerPage ?? 24));
            $page = max(1, $projectPage ?? 1);
            $total = $items->count();
            $projectPagination = [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ];
            $items = $items->forPage($page, $perPage);
        }

        return [
            'profile' => [
                'name' => $user->name,
                'headline' => $user->portfolio_headline,
                'location' => $user->portfolio_location,
                'image_url' => $snapshot['profile_image_url'],
                'skills' => $user->portfolio_skills ?? [],
                'links' => collect($user->portfolio_links ?? [])
                    ->map(function ($link): ?array {
                        if (!is_array($link)) {
                            return null;
                        }
                        $safeUrl = SafeExternalUrl::sanitize($link['url'] ?? null);
                        if (!$safeUrl) {
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
                'slug' => $slug,
                'public_url' => $adminPreview ? null : RoknPublicUrl::portfolio($slug),
                'share_mode' => 'unlisted',
            ],
            'projects' => $items
                ->map(fn (PortfolioItem $item): array => $this->publicProjectPayload(
                    $item,
                    $slug,
                    $adminPreview,
                    $snapshot
                ))
                ->values()
                ->all(),
            'projects_pagination' => $projectPagination,
        ];
    }

    /** Public share payloads use public slugs/UUIDs, never database keys. */
    private function publicProjectPayload(
        PortfolioItem $item,
        string $slug,
        bool $adminPreview,
        array $snapshot
    ): array
    {
        $media = $item->mediaFiles
            ->map(function (PortfolioMedia $media) use ($slug, $item, $adminPreview, $snapshot): ?array {
                $mediaPublicId = (string) $media->public_id;
                $deliveryUrl = Str::isUuid($mediaPublicId)
                    && in_array((string) $media->file_type, ['image', 'video'], true)
                    && !$media->deletion_lease_id
                    ? ($adminPreview
                        ? route('admin.portfolio-preview.media', ['user' => $item->user_id, 'mediaId' => $mediaPublicId])
                        : RoknPublicUrl::portfolioMedia($slug, $mediaPublicId))
                    : null;
                if (!$deliveryUrl) {
                    return null;
                }
                // These are Rokn delivery routes, NOT signed CDN URLs. The
                // controller validates this revision before issuing a CDN URL.
                $deliveryUrl .= '?' . http_build_query([
                    'revision' => $snapshot['revision'], 'snapshot' => $snapshot['hash'],
                ]);

                $publicMedia = [
                    'file_type' => $media->file_type,
                    'caption' => $media->caption,
                    'width' => $media->width,
                    'height' => $media->height,
                    'duration_seconds' => $media->duration_seconds,
                ];
                if ($media->file_type === 'image') {
                    $publicMedia['image_url'] = $deliveryUrl;
                } elseif ($media->file_type === 'video') {
                    $publicMedia['video_url'] = $deliveryUrl;
                }

                return $publicMedia;
            })
            ->filter()
            ->values()
            ->all();
        $course = $item->course
            ? ['name' => $item->course->name_ar ?: $item->course->name_en]
            : null;

        return [
            'title' => (string) $item->title,
            'description' => $item->description,
            'role' => $item->role,
            'tools' => $item->tools ?? [],
            'external_url' => SafeExternalUrl::sanitize($item->external_url),
            'completed_at' => $item->completed_at?->format('Y-m-d'),
            'is_featured' => (bool) $item->is_featured,
            'course' => $course,
            'media' => $media,
        ];
    }
}
