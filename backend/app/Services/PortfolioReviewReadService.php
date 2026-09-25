<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use App\Support\RoknPublicUrl;

/** Reads the reviewed content and effective approval state; never invalidates or grants approval. */
final readonly class PortfolioReviewReadService
{
    public const PROFILE_FIELDS = [
        'name', 'name_ar', 'name_en', 'profile_image', 'role', 'active',
        'portfolio_slug', 'portfolio_headline', 'portfolio_location',
        'portfolio_skills', 'portfolio_links',
    ];

    public function __construct(private PortfolioShareIdentityService $shareIdentity)
    {
    }

    /** Review decisions hold the owner lock; reads fail closed against this content hash. */
    public function snapshot(User $user): array
    {
        $items = $user->portfolioItems()->shareable()
            ->with(['mediaFiles' => fn ($media) => $media->available(), 'course'])
            ->orderByDesc('is_featured')->orderBy('sort_order')->latest('id')->get();
        $content = [
            'profile' => array_intersect_key($user->getAttributes(), array_flip(self::PROFILE_FIELDS)),
            'profile_image_url' => $user->profile_image_url,
            'projects' => $items->map(static fn (PortfolioItem $item): array => [
                'item' => array_intersect_key($item->getAttributes(), array_flip([
                    'id', 'title', 'description', 'role', 'tools', 'external_url',
                    'completed_at', 'is_featured', 'sort_order', 'course_id',
                ])),
                'course' => $item->course?->only(['name_ar', 'name_en']),
                'media' => $item->mediaFiles->map(static fn (PortfolioMedia $media): array =>
                    array_intersect_key($media->getAttributes(), array_flip([
                        'id', 'public_id', 'file_path', 'file_type', 'content_sha256',
                        'caption', 'width', 'height', 'duration_seconds', 'sort_order',
                    ]))
                )->all(),
            ])->all(),
        ];

        return [
            'user' => $user,
            'items' => $items,
            'profile_image_url' => $content['profile_image_url'],
            'revision' => (int) $user->portfolio_sharing_revision,
            'hash' => hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /** Evaluation may fail closed without writing the stored review state. */
    public function status(User $user, ?array $snapshot = null): string
    {
        if ($user->portfolio_sharing_suspended_at !== null) {
            return 'suspended';
        }
        $status = (string) $user->portfolio_sharing_status;
        if ($status !== 'approved') {
            return $status === 'rejected' ? 'rejected' : 'pending';
        }

        $snapshot ??= $this->snapshot($user);
        if (!$user->active
            || !$this->shareIdentity->isValidUnlistedSlug((string) $user->portfolio_slug)
            || !hash_equals((string) $user->portfolio_approved_hash, $snapshot['hash'])) {
            return 'pending';
        }

        return 'approved';
    }

    /** Resolve the current share link, not a stale model's cached slug or approval. */
    public function publicUrlFor(User $owner): ?string
    {
        if (!$owner->exists) {
            return null;
        }
        $current = User::query()->find($owner->id);
        if (!$current || $this->status($current) !== 'approved') {
            return null;
        }

        return RoknPublicUrl::portfolio((string) $current->portfolio_slug);
    }
}
