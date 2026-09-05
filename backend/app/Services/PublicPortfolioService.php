<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use App\Support\RoknPublicUrl;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class PublicPortfolioService
{
    public function __construct(
        private readonly PortfolioShareIdentityService $shareIdentity
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

        return $this->fullPortfolio($user, $slug, $projectPage, $projectsPerPage);
    }

    public function mediaForPortfolio(string $slug, string $mediaPublicId): ?PortfolioMedia
    {
        $user = $this->userForSlug($slug);
        if (!$user || !Str::isUuid($mediaPublicId)) {
            return null;
        }

        return PortfolioMedia::query()
            ->where('public_id', $mediaPublicId)
            ->available()
            ->whereHas('portfolioItem', fn ($items) =>
                $items->where('user_id', $user->id)
                    ->shareable()
            )
            ->first();
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

        $user = User::query()->where('portfolio_slug', $slug)->first();
        if (!$user) {
            return null;
        }

        return $user;
    }

    private function fullPortfolio(
        User $user,
        string $slug,
        ?int $projectPage,
        ?int $projectsPerPage
    ): array
    {
        $itemsQuery = $user->portfolioItems()
            ->shareable()
            ->with(['mediaFiles', 'course'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->latest('id');
        $projectPagination = null;
        if ($projectPage !== null || $projectsPerPage !== null) {
            /** @var LengthAwarePaginator $paginator */
            $paginator = $itemsQuery->paginate(
                max(1, min(100, $projectsPerPage ?? 24)),
                ['*'],
                'page',
                max(1, $projectPage ?? 1)
            );
            $items = collect($paginator->items());
            $projectPagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];
        } else {
            $items = $itemsQuery->get();
        }

        return [
            'profile' => [
                'name' => $user->name,
                'headline' => $user->portfolio_headline,
                'location' => $user->portfolio_location,
                'image_url' => $user->profile_image_url,
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
                'public_url' => RoknPublicUrl::portfolio($slug),
                'share_mode' => 'unlisted',
            ],
            'projects' => $items
                ->map(fn (PortfolioItem $item): array => $this->publicProjectPayload(
                    $item,
                    $slug
                ))
                ->values()
                ->all(),
            'projects_pagination' => $projectPagination,
        ];
    }

    /** Public share payloads use public slugs/UUIDs, never database keys. */
    private function publicProjectPayload(
        PortfolioItem $item,
        string $slug
    ): array
    {
        $media = $item->mediaFiles
            ->map(function (PortfolioMedia $media) use ($slug): ?array {
                $mediaPublicId = (string) $media->public_id;
                $deliveryUrl = Str::isUuid($mediaPublicId)
                    && in_array((string) $media->file_type, ['image', 'video'], true)
                    && !$media->deletion_lease_id
                    ? RoknPublicUrl::portfolioMedia($slug, $mediaPublicId)
                    : null;
                if (!$deliveryUrl) {
                    return null;
                }

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
