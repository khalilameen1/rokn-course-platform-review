<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\PortfolioPublication;
use App\Models\User;
use App\Support\RoknPublicUrl;

/**
 * Reads the reviewed content and effective approval state; never invalidates
 * or grants approval.
 *
 * Draft/published model (CMS publish workflow; App Store Connect keeps the
 * live version available while the next version is in review): the draft is
 * the owner's current selection, the publication is the last admin-approved
 * content. Owner removals (delete, hide, delete media) apply to the
 * publication immediately; additions and edits wait for the next approval.
 */
final readonly class PortfolioReviewReadService
{
    public const PROFILE_FIELDS = [
        'name', 'name_ar', 'name_en', 'profile_image', 'role', 'active',
        'portfolio_slug', 'portfolio_headline',
        'portfolio_skills', 'portfolio_links',
    ];

    private const ITEM_FIELDS = [
        'id', 'title', 'description', 'role', 'tools', 'external_url',
        'completed_at', 'is_featured', 'sort_order', 'course_id',
    ];

    private const MEDIA_FIELDS = [
        'id', 'public_id', 'file_path', 'file_type', 'content_sha256',
        'caption', 'width', 'height', 'duration_seconds', 'sort_order',
    ];

    public function __construct(private PortfolioShareIdentityService $shareIdentity) {}

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
                'item' => array_intersect_key($item->getAttributes(), array_flip(self::ITEM_FIELDS)),
                'course' => $item->course?->only(['name_ar', 'name_en']),
                'media' => $item->mediaFiles->map(static fn (PortfolioMedia $media): array => array_intersect_key($media->getAttributes(), array_flip(self::MEDIA_FIELDS))
                )->all(),
            ])->all(),
        ];

        return [
            'user' => $user,
            'items' => $items,
            'content' => $content,
            'profile_image_url' => $content['profile_image_url'],
            'revision' => (int) $user->portfolio_sharing_revision,
            'hash' => self::hashContent($content),
        ];
    }

    public static function hashContent(array $content): string
    {
        return hash('sha256', json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** Evaluation may fail closed without writing the stored review state. */
    public function status(User $user, ?array $snapshot = null): string
    {
        if ($user->portfolio_sharing_suspended_at !== null) {
            return 'suspended';
        }
        $status = (string) $user->portfolio_sharing_status;
        $publishable = $user->active && $this->shareIdentity->isValidUnlistedSlug((string) $user->portfolio_slug);
        if ($status === 'approved' || PortfolioPublication::query()->where('user_id', $user->id)->exists()) {
            $snapshot ??= $this->snapshot($user);
            if ($publishable && $status === 'approved'
                && hash_equals((string) $user->portfolio_approved_hash, $snapshot['hash'])) {
                return 'approved';
            }
            // Nothing new to review: the draft equals what is already live
            // (for example after hiding or deleting a project).
            if ($publishable && $this->draftMatchesPublication($user, $snapshot)) {
                return 'approved';
            }
        }

        return $status === 'rejected' ? 'rejected' : 'pending';
    }

    /**
     * Stored publication, or a pre-publication approval whose content still
     * matches its approved hash (approved before publications were stored).
     *
     * @return array{content:array,hash:string,revision:int,published_at:mixed,stored:bool}|null
     */
    public function publication(User $user, ?array $snapshot = null): ?array
    {
        $row = PortfolioPublication::query()->where('user_id', $user->id)->first();
        if ($row) {
            $content = json_decode((string) $row->content, true);
            if (! is_array($content)) {
                return null;
            }

            return [
                'content' => $content,
                'hash' => (string) $row->content_hash,
                'revision' => (int) $row->revision,
                'published_at' => $row->published_at,
                'stored' => true,
            ];
        }
        if ($user->portfolio_sharing_status !== 'approved' || ! $user->portfolio_approved_hash) {
            return null;
        }
        $snapshot ??= $this->snapshot($user);
        if (! hash_equals((string) $user->portfolio_approved_hash, $snapshot['hash'])) {
            return null;
        }

        return [
            'content' => $snapshot['content'],
            'hash' => $snapshot['hash'],
            'revision' => $snapshot['revision'],
            'published_at' => $user->portfolio_reviewed_at,
            'stored' => false,
        ];
    }

    /**
     * The publication as the public may see it now, or null when the link is
     * not live (suspended, inactive, invalid slug or never approved).
     *
     * @return array{publication:array,projects:list<array{content:array,item:PortfolioItem}>}|null
     */
    public function livePublication(User $user, ?array $snapshot = null): ?array
    {
        if ($user->portfolio_sharing_suspended_at !== null
            || ! $user->active
            || ! $this->shareIdentity->isValidUnlistedSlug((string) $user->portfolio_slug)) {
            return null;
        }
        $publication = $this->publication($user, $snapshot);
        if (! $publication) {
            return null;
        }

        return ['publication' => $publication, 'projects' => $this->effectiveProjects($user, $publication['content'])];
    }

    /**
     * Approved projects minus anything the owner removed, hid or replaced
     * since approval (privacy wins). Ordering and featuring are owner
     * presentation choices and follow the live rows.
     *
     * @return list<array{content:array,item:PortfolioItem}>
     */
    public function effectiveProjects(User $user, array $content): array
    {
        $projects = array_values(array_filter((array) ($content['projects'] ?? []), 'is_array'));
        $ids = array_values(array_unique(array_map(static fn (array $p): int => (int) ($p['item']['id'] ?? 0), $projects)));
        if ($ids === []) {
            return [];
        }
        $live = PortfolioItem::query()->where('user_id', $user->id)->whereIn('id', $ids)
            ->available()->whereNull('hidden_at')
            ->with(['mediaFiles' => fn ($media) => $media->available()])
            ->get()->keyBy('id');

        $effective = [];
        foreach ($projects as $project) {
            $item = $live->get((int) ($project['item']['id'] ?? 0));
            if (! $item) {
                continue;
            }
            $liveMedia = $item->mediaFiles->keyBy('id');
            $project['media'] = array_values(array_filter((array) ($project['media'] ?? []), static function ($media) use ($liveMedia): bool {
                $row = is_array($media) ? $liveMedia->get((int) ($media['id'] ?? 0)) : null;

                return $row
                    && (string) $row->public_id === (string) ($media['public_id'] ?? '')
                    && (string) $row->file_type === (string) ($media['file_type'] ?? '')
                    && (string) $row->file_path === (string) ($media['file_path'] ?? '');
            }));
            if ($project['media'] === []) {
                continue;
            }
            $raw = $item->getAttributes();
            $project['item']['is_featured'] = $raw['is_featured'] ?? null;
            $project['item']['sort_order'] = $raw['sort_order'] ?? null;
            $effective[] = ['content' => $project, 'item' => $item];
        }
        usort($effective, static fn (array $a, array $b): int => [
            -(int) (bool) $a['content']['item']['is_featured'], (int) $a['content']['item']['sort_order'], -(int) $a['item']->id,
        ] <=> [
            -(int) (bool) $b['content']['item']['is_featured'], (int) $b['content']['item']['sort_order'], -(int) $b['item']->id,
        ]);

        return $effective;
    }

    /**
     * The owner's sharing state as the reconciled state would show it, without
     * writing: reconcile() persists the same status from the write path.
     *
     * @return array{sharing_status:string,sharing_revision:int,sharing_rejection_reason:?string,sharing_suspended:bool,published:bool,published_at:?string,has_unpublished_changes:bool}
     */
    public function ownerState(User $user): array
    {
        $current = User::query()->whereKey($user->id)->firstOrFail();
        $snapshot = $this->snapshot($current);
        $status = $this->status($current, $snapshot);
        $live = $this->livePublication($current, $snapshot);
        $publishedAt = $live['publication']['published_at'] ?? null;

        return [
            'sharing_status' => $status,
            'sharing_revision' => (int) $current->portfolio_sharing_revision,
            'sharing_rejection_reason' => $status === 'rejected'
                ? $current->portfolio_sharing_rejection_reason : null,
            'sharing_suspended' => $current->portfolio_sharing_suspended_at !== null,
            // The link stays live on the last approved version while a newer
            // draft is pending or rejected.
            'published' => $live !== null,
            'published_at' => $publishedAt instanceof \DateTimeInterface ? $publishedAt->format(DATE_ATOM) : null,
            'has_unpublished_changes' => $live !== null && $status !== 'approved',
        ];
    }

    /** Resolve the current share link, not a stale model's cached slug or approval. */
    public function publicUrlFor(User $owner): ?string
    {
        if (! $owner->exists) {
            return null;
        }
        $current = User::query()->find($owner->id);
        if (! $current || $this->livePublication($current) === null) {
            return null;
        }

        return RoknPublicUrl::portfolio((string) $current->portfolio_slug);
    }

    private function draftMatchesPublication(User $user, array $snapshot): bool
    {
        $publication = PortfolioPublication::query()->where('user_id', $user->id)->first();
        $content = $publication ? json_decode((string) $publication->content, true) : null;

        return is_array($content) && hash_equals($this->effectiveHash($user, $content), $snapshot['hash']);
    }

    /** Hash of the publication as it is live now, comparable with a draft snapshot hash. */
    public function effectiveHash(User $user, array $content): string
    {
        return self::hashContent([
            'profile' => $content['profile'] ?? null,
            'profile_image_url' => $content['profile_image_url'] ?? null,
            'projects' => array_map(static fn (array $p): array => $p['content'], $this->effectiveProjects($user, $content)),
        ]);
    }
}
