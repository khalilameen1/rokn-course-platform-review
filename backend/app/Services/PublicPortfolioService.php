<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\PortfolioContactLink;
use App\Support\RoknPublicUrl;
use Illuminate\Support\Str;

/**
 * Public pages render the last approved publication (draft/published model),
 * minus anything the owner has since removed or hidden. The admin preview
 * renders the current draft that is waiting for review.
 */
final class PublicPortfolioService
{
    public function __construct(
        private readonly PortfolioShareIdentityService $shareIdentity,
        private readonly PortfolioModerationService $moderation,
        private readonly PortfolioReviewReadService $reviews,
        private readonly CourseRevisionResolver $revisions
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

        // Keeps the review queue and a pre-publication approval current; the
        // public result no longer depends on the draft being approved.
        $this->moderation->reconcile($user, $this->reviews->snapshot($user));
        $live = $this->reviews->livePublication($user);
        if (!$live) {
            return null;
        }
        $publication = $live['publication'];

        return $this->portfolioPayload(
            $user,
            (array) ($publication['content']['profile'] ?? []),
            $publication['content']['profile_image_url'] ?? null,
            $live['projects'],
            $slug,
            ['revision' => $publication['revision'], 'snapshot' => $publication['hash']],
            false,
            $projectPage,
            $projectsPerPage
        );
    }

    /** One approved project with its own share URL (Behance-style project page). */
    public function project(string $slug, string $projectPublicId): ?array
    {
        if (!Str::isUuid($projectPublicId)) {
            return null;
        }
        $portfolio = $this->find($slug);
        $project = collect($portfolio['projects'] ?? [])
            ->first(fn (array $project): bool => strtolower((string) ($project['public_id'] ?? '')) === strtolower($projectPublicId));
        if (!$portfolio || !$project) {
            return null;
        }

        return [
            'profile' => $portfolio['profile'],
            'project' => $project,
            'more_projects' => collect($portfolio['projects'])
                ->reject(fn (array $other): bool => $other['public_id'] === $project['public_id'])
                ->take(4)->values()->all(),
            'share_image' => $this->shareImage([$project]),
        ];
    }

    public function mediaForPortfolio(string $slug, string $mediaPublicId, string $revision = '', string $hash = ''): ?PortfolioMedia
    {
        $user = $this->userForSlug($slug);
        if (!$user || !Str::isUuid($mediaPublicId)) return null;
        $live = $this->reviews->livePublication($user);
        if (!$live
            || (string) $live['publication']['revision'] !== $revision
            || !hash_equals($live['publication']['hash'], $hash)) {
            return null;
        }
        foreach ($live['projects'] as $entry) {
            $approved = array_column($entry['content']['media'], 'public_id');
            $media = $entry['item']->mediaFiles->first(fn (PortfolioMedia $media): bool =>
                $media->public_id === $mediaPublicId && in_array($mediaPublicId, $approved, true));
            if ($media) {
                return $media;
            }
        }

        return null;
    }

    /** Only the admin-only preview controller may call this suspension bypass. */
    public function adminPreview(User $user): array
    {
        $user = $user->fresh();
        $status = $this->moderation->reconcile($user);
        $snapshot = $this->reviews->snapshot($user);
        $live = $this->reviews->livePublication($user);

        return $this->portfolioPayload(
            $user,
            $snapshot['content']['profile'],
            $snapshot['profile_image_url'],
            $this->draftEntries($snapshot),
            (string) $user->portfolio_slug,
            ['revision' => $snapshot['revision'], 'snapshot' => $snapshot['hash']],
            true,
            null,
            null
        ) + [
            'review' => [
                'status' => $status,
                'revision' => $snapshot['revision'],
                'snapshot_hash' => $snapshot['hash'],
                'rejection_reason' => $user->portfolio_sharing_rejection_reason,
                'published' => $live !== null,
            ],
        ];
    }

    /** Returns only selected work, never the learner's private drafts. */
    public function adminPreviewMedia(User $user, string $mediaPublicId, string $revision = '', string $hash = ''): ?PortfolioMedia
    {
        $snapshot = $this->reviews->snapshot($user->fresh());
        if ((string) $snapshot['revision'] !== $revision || !hash_equals($snapshot['hash'], $hash)
            || !Str::isUuid($mediaPublicId)) {
            return null;
        }

        return $snapshot['items']->flatMap(fn (PortfolioItem $item) => $item->mediaFiles)
            ->first(fn (PortfolioMedia $media) => $media->public_id === $mediaPublicId);
    }

    /** @return list<array{content:array,item:PortfolioItem}> */
    private function draftEntries(array $snapshot): array
    {
        return $snapshot['items']->values()->map(fn (PortfolioItem $item, int $index): array => [
            'content' => $snapshot['content']['projects'][$index],
            'item' => $item,
        ])->all();
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

        return User::query()->where('portfolio_slug', $slug)
            ->whereNull('portfolio_sharing_suspended_at')->where('active', true)->first();
    }

    private function portfolioPayload(
        User $owner,
        array $profileContent,
        ?string $profileImageUrl,
        array $entries,
        string $slug,
        array $mediaQuery,
        bool $adminPreview,
        ?int $projectPage,
        ?int $projectsPerPage
    ): array {
        $profile = (new User())->setRawAttributes($profileContent);
        $projectPagination = null;
        if ($projectPage !== null || $projectsPerPage !== null) {
            $perPage = max(1, min(100, $projectsPerPage ?? 24));
            $page = max(1, $projectPage ?? 1);
            $total = count($entries);
            $projectPagination = [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ];
            $entries = array_slice($entries, ($page - 1) * $perPage, $perPage);
        }
        $credentials = $this->credentials((int) $owner->id, $entries);
        $projects = array_map(fn (array $entry): array => $this->projectPayload(
            $entry, $slug, $adminPreview, $mediaQuery, $credentials[(int) $entry['item']->id] ?? []
        ), $entries);

        // A replaced or removed avatar is never shown from an older approval.
        $sameAvatar = (string) ($profileContent['profile_image'] ?? '') === (string) ($owner->getAttributes()['profile_image'] ?? '');

        return [
            'profile' => [
                'name' => $profile->name,
                'headline' => $profile->portfolio_headline,
                'location' => $profile->portfolio_location,
                'image_url' => $adminPreview || $sameAvatar ? $profileImageUrl : null,
                'skills' => array_values(array_filter((array) ($profile->portfolio_skills ?? []), 'is_string')),
                'links' => $this->contactLinks((array) ($profile->portfolio_links ?? []), $adminPreview ? null : $owner),
                'slug' => $slug,
                'public_url' => $adminPreview ? null : RoknPublicUrl::portfolio($slug),
                'share_mode' => 'unlisted',
            ],
            'projects' => $projects,
            'projects_pagination' => $projectPagination,
            'share_image' => $this->shareImage($projects),
        ];
    }

    /**
     * Contact buttons from the learner's own profile links. A link removed
     * from the profile disappears immediately even before the next review.
     *
     * @return list<array{label:string,url:string,type:string,type_label:string}>
     */
    private function contactLinks(array $links, ?User $currentOwner): array
    {
        $current = null;
        if ($currentOwner) {
            $current = collect((array) ($currentOwner->portfolio_links ?? []))
                ->map(fn ($link) => is_array($link) ? PortfolioContactLink::normalize($link['url'] ?? null) : null)
                ->filter()->flip()->all();
        }

        return collect($links)->map(function ($link) use ($current): ?array {
            $url = is_array($link) ? PortfolioContactLink::normalize($link['url'] ?? null) : null;
            if (!$url || ($current !== null && !isset($current[$url]))) {
                return null;
            }
            $type = PortfolioContactLink::type($url);
            $label = trim((string) ($link['label'] ?? ''));

            return [
                'label' => $label !== '' ? $label : PortfolioContactLink::LABELS[$type],
                'url' => $url,
                'type' => $type,
                'type_label' => PortfolioContactLink::LABELS[$type],
            ];
        })->filter()->unique('url')->values()->all();
    }

    /** Public share payloads use public slugs/UUIDs, never database keys. */
    private function projectPayload(array $entry, string $slug, bool $adminPreview, array $mediaQuery, array $credential): array
    {
        $content = $entry['content'];
        /** @var PortfolioItem $live */
        $live = $entry['item'];
        $item = (new PortfolioItem())->setRawAttributes((array) ($content['item'] ?? []));
        $coverId = (int) $live->cover_media_id;
        $media = [];
        $cover = null;
        foreach ((array) ($content['media'] ?? []) as $raw) {
            $payload = is_array($raw) ? $this->mediaPayload($raw, $slug, (int) $live->user_id, $adminPreview, $mediaQuery) : null;
            if (!$payload) {
                continue;
            }
            $media[] = $payload;
            if ($coverId > 0 && (int) ($raw['id'] ?? 0) === $coverId && $payload['file_type'] === 'image') {
                $cover = $payload;
            }
        }
        $cover ??= collect($media)->firstWhere('file_type', 'image') ?? ($media[0] ?? null);
        $course = is_array($content['course'] ?? null)
            ? ['name' => ($content['course']['name_ar'] ?? null) ?: ($content['course']['name_en'] ?? null)]
            : null;
        $publicId = (string) $live->public_id;

        return [
            'public_id' => $publicId !== '' ? $publicId : null,
            'url' => !$adminPreview && $publicId !== '' ? RoknPublicUrl::portfolioProject($slug, $publicId) : null,
            'title' => (string) $item->title,
            'description' => $item->description,
            'role' => $item->role,
            'tools' => array_values(array_filter((array) ($item->tools ?? []), 'is_string')),
            'external_url' => SafeExternalUrl::sanitize($item->external_url),
            'completed_at' => $item->completed_at?->format('Y-m-d'),
            'is_featured' => (bool) $live->is_featured,
            'course' => $course && $course['name'] ? $course : null,
            'verified' => (bool) ($credential['verified'] ?? false),
            'certificate_url' => $credential['certificate_url'] ?? null,
            'cover' => $cover,
            'media' => $media,
        ];
    }

    private function mediaPayload(array $raw, string $slug, int $ownerId, bool $adminPreview, array $mediaQuery): ?array
    {
        $publicId = (string) ($raw['public_id'] ?? '');
        $type = (string) ($raw['file_type'] ?? '');
        if (!Str::isUuid($publicId) || !in_array($type, ['image', 'video'], true)) {
            return null;
        }
        // These are Rokn delivery routes, NOT signed CDN URLs. The controller
        // validates this publication before issuing a CDN URL.
        $base = $adminPreview
            ? route('admin.portfolio-preview.media', ['user' => $ownerId, 'mediaId' => $publicId])
            : RoknPublicUrl::portfolioMedia($slug, $publicId);
        $url = $base . '?' . http_build_query($mediaQuery);
        $width = (int) ($raw['width'] ?? 0);
        $height = (int) ($raw['height'] ?? 0);

        $payload = [
            'file_type' => $type,
            'caption' => $raw['caption'] ?? null,
            'width' => $width > 0 ? $width : null,
            'height' => $height > 0 ? $height : null,
            'duration_seconds' => isset($raw['duration_seconds']) ? (int) $raw['duration_seconds'] : null,
        ];
        if ($type === 'image') {
            $payload['image_url'] = $url;
        } else {
            $payload['video_url'] = $url;
            $payload['poster_url'] = $adminPreview ? null : $base . '?' . http_build_query($mediaQuery + ['variant' => 'poster']);
        }

        return $payload;
    }

    /** First approved image for Open Graph / Twitter cards. */
    private function shareImage(array $projects): ?array
    {
        foreach ($projects as $project) {
            $candidates = array_merge($project['cover'] ? [$project['cover']] : [], $project['media'] ?? []);
            foreach ($candidates as $media) {
                if (($media['file_type'] ?? null) === 'image' && !empty($media['image_url'])) {
                    return [
                        'url' => $media['image_url'],
                        'width' => $media['width'],
                        'height' => $media['height'],
                        'alt' => ($media['caption'] ?? null) ?: $project['title'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * "Reviewed in Rokn" badge: the project came from a course project that
     * passed review; link the course certificate when it is active and valid.
     *
     * @return array<int,array{verified:bool,certificate_url:?string}>
     */
    private function credentials(int $userId, array $entries): array
    {
        $sources = [];
        $courses = [];
        foreach ($entries as $entry) {
            $item = $entry['item'];
            if ($item->source_project_id) {
                $sources[(int) $item->id] = (int) $item->source_project_id;
                $courses[(int) $item->id] = (int) ($entry['content']['item']['course_id'] ?? $item->course_id);
            }
        }
        if ($sources === []) {
            return [];
        }
        $equivalents = $this->revisions->equivalentEntityMap(Project::class, array_values(array_unique($sources)));
        $projectIds = array_values(array_unique(array_merge(array_values($sources), ...array_values($equivalents))));
        $passed = ProjectSubmission::query()->where('user_id', $userId)
            ->whereIn('project_id', $projectIds)
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->pluck('project_id')->map(fn ($id): int => (int) $id)->flip();

        $verified = [];
        foreach ($sources as $itemId => $sourceId) {
            foreach ($equivalents[$sourceId] ?? [$sourceId] as $projectId) {
                if ($passed->has($projectId)) {
                    $verified[$itemId] = true;
                    break;
                }
            }
        }
        $courseIds = array_values(array_unique(array_filter(array_intersect_key($courses, $verified))));
        $certificates = $courseIds === [] ? collect() : Certificate::query()
            ->where('user_id', $userId)->whereIn('course_id', $courseIds)
            ->where('status', 'active')->whereNull('revoked_at')
            ->orderByDesc('generated_at')->orderByDesc('id')->get()
            ->filter(fn (Certificate $certificate): bool => $certificate->isActiveCredential()
                && $certificate->hasCompleteCredentialSnapshot())
            ->unique('course_id')->keyBy('course_id');

        $result = [];
        foreach (array_keys($verified) as $itemId) {
            $certificate = $certificates->get($courses[$itemId] ?? 0);
            $result[$itemId] = [
                'verified' => true,
                'certificate_url' => $certificate ? RoknPublicUrl::certificate((string) $certificate->public_id) : null,
            ];
        }

        return $result;
    }
}
