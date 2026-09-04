<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\PublicDiskUrl;
use App\Support\RoknPublicUrl;

use App\Http\Resources\CertificateResource;
use App\Http\Resources\PortfolioItemResource;
use App\Models\Certificate;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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

    /** Resolve a credential without relying on a mutable profile slug. */
    public function findCredential(string $publicId): ?array
    {
        if (!Str::isUuid($publicId)) {
            return null;
        }

        $certificate = Certificate::query()
            ->where('public_id', $publicId)
            ->with('user')
            ->first();
        if (!$certificate) {
            return null;
        }
        if ($certificate->isRevokedCredential()) {
            // A deleted account removes the holder identity and artifact, but
            // the random credential URL must continue to say “revoked” rather
            // than becoming indistinguishable from an unknown/forged ID.
            return $this->limitedVerificationPayload($certificate);
        }
        if (
            !$certificate->isActiveCredential()
            || !$certificate->hasCompleteCredentialSnapshot()
            || !$certificate->hasStoredArtifact()
            || !$certificate->user
        ) {
            return null;
        }

        return $this->activeVerificationPayload($certificate);
    }

    public function mediaForPortfolio(string $slug, string $mediaPublicId): ?PortfolioMedia
    {
        $user = $this->userForSlug($slug);
        if (!$user || !Str::isUuid($mediaPublicId)) return null;

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
            ->withCount('mediaFiles')
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
        $certificates = Certificate::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where('image_path', '!=', 'pending')
            ->with('user')
            ->latest('generated_at')
            ->get()
            ->filter(fn (Certificate $certificate): bool =>
                $certificate->hasCompleteCredentialSnapshot()
                    && $certificate->hasStoredArtifact()
            )
            ->values();

        $badges = DB::table('user_level')
            ->join('levels', 'levels.id', '=', 'user_level.level_id')
            ->join('courses', 'courses.id', '=', 'user_level.course_id')
            ->where('user_level.user_id', $user->id)
            ->where('courses.awards_badge', true)
            ->whereIn('courses.badge_track', ['professional', 'freelance'])
            ->orderByDesc('user_level.earned_at')
            ->get([
                'user_level.id as award_id', 'levels.id as level_id',
                'levels.name_ar', 'levels.name_en', 'levels.badge_image', 'levels.order',
                'courses.id as course_id', 'courses.name_ar as course_name_ar',
                'courses.name_en as course_name_en', 'courses.badge_track',
                'user_level.earned_at',
            ])
            ->map(function ($badge) {
                $path = (string) ($badge->badge_image ?? '');
                if ($path !== '' && filter_var($path, FILTER_VALIDATE_URL)) {
                    $badge->badge_image = $path;
                } elseif ($path !== '' && str_starts_with(ltrim($path, '/'), 'assets/')) {
                    $badge->badge_image = asset(ltrim($path, '/'));
                } elseif ($path !== '') {
                    $badge->badge_image = PublicDiskUrl::from($path);
                } else {
                    $fallback = (int) $badge->order <= 1
                        ? 'junior.png'
                        : ((int) $badge->order === 2 ? 'mid-level.png' : 'senior.png');
                    $badge->badge_image = asset('assets/img/badges/' . $fallback);
                }

                unset($badge->award_id, $badge->level_id, $badge->course_id, $badge->order);
                return $badge;
            });

        return [
            'profile' => [
                'name' => $user->name,
                'headline' => $user->portfolio_headline ?: $user->job_title,
                'bio' => $user->bio,
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
                'is_public' => true,
            ],
            'projects' => $items
                ->map(fn (PortfolioItem $item): array => $this->publicProjectPayload(
                    $item,
                    $slug
                ))
                ->values()
                ->all(),
            'projects_pagination' => $projectPagination,
            'certificates' => $certificates
                ->map(fn (Certificate $certificate): array => $this->publicCertificatePayload($certificate))
                ->values()
                ->all(),
            'highlighted_certificate' => null,
            'badges' => $badges,
            'is_limited_certificate_view' => false,
        ];
    }

    /** Public share payloads use public slugs/UUIDs, never database keys. */
    private function publicProjectPayload(
        PortfolioItem $item,
        string $slug
    ): array
    {
        $payload = (new PortfolioItemResource($item))->resolve();
        unset(
            $payload['id'],
            $payload['source_project_id'],
            $payload['created_at'],
            $payload['updated_at']
        );
        if (is_array($payload['course'] ?? null)) {
            unset($payload['course']['id']);
        }
        if (is_array($payload['media'] ?? null)) {
            $payload['media'] = array_map(function ($media) use ($slug): mixed {
                if (!is_array($media)) return $media;
                $mediaPublicId = (string) ($media['public_id'] ?? '');
                $deliveryUrl = Str::isUuid($mediaPublicId)
                    && ($media['status'] ?? null) === 'ready'
                    ? RoknPublicUrl::portfolioMedia($slug, $mediaPublicId)
                    : null;
                if (($media['file_type'] ?? null) === 'image') {
                    $media['image_url'] = $deliveryUrl;
                } elseif (($media['file_type'] ?? null) === 'video') {
                    $media['video_url'] = $deliveryUrl;
                }
                unset($media['id'], $media['public_id'], $media['playback_url']);
                return $media;
            }, $payload['media']);
        }

        return $payload;
    }

    private function publicCertificatePayload(Certificate $certificate): array
    {
        $payload = (new CertificateResource($certificate))->resolve();
        unset($payload['course_id']);

        return $payload;
    }

    /** A certificate UUID verifies that credential only; it is not a portfolio share key. */
    private function activeVerificationPayload(Certificate $certificate): array
    {
        $payload = $this->publicCertificatePayload($certificate);
        $holderName = trim((string) ($payload['holder_name'] ?? '')) ?: 'طالب ركن';
        $verificationUrl = RoknPublicUrl::certificate((string) $certificate->public_id);

        return [
            'profile' => [
                'name' => $holderName,
                'headline' => null,
                'bio' => null,
                'location' => null,
                'image_url' => null,
                'skills' => [],
                'links' => [],
                'slug' => null,
                'public_url' => $verificationUrl,
                'share_mode' => 'verification_only',
                'is_public' => false,
            ],
            'projects' => [],
            'certificates' => [],
            'highlighted_certificate' => $payload,
            'badges' => [],
            'is_limited_certificate_view' => true,
        ];
    }

    /** A revoked credential proves status only and exposes no live profile. */
    private function limitedVerificationPayload(Certificate $certificate): array
    {
        $holderName = trim((string) $certificate->holder_name) ?: 'طالب ركن';
        $courseName = trim((string) $certificate->course_name) ?: 'كورس ركن';
        $verificationUrl = RoknPublicUrl::certificate((string) $certificate->public_id);

        return [
            'profile' => [
                'name' => $holderName,
                'headline' => null,
                'bio' => null,
                'location' => null,
                'image_url' => null,
                'skills' => [],
                'links' => [],
                'slug' => null,
                'public_url' => $verificationUrl,
                'share_mode' => 'verification_only',
                'is_public' => false,
            ],
            'projects' => [],
            'certificates' => [],
            'highlighted_certificate' => [
                'public_id' => (string) $certificate->public_id,
                'holder_name' => $holderName,
                'course_name' => $courseName,
                'certificate_text_template_key' => trim((string) $certificate->certificate_text_template_key) ?: null,
                'certificate_text' => trim((string) $certificate->certificate_text) ?: null,
                'certificate_url' => '',
                'verification_url' => $verificationUrl,
                'status' => 'revoked',
                'verification_level' => $certificate->verification_level ?? 'completion',
                'verification_label' => 'شهادة ملغاة',
                'generated_at' => $certificate->generated_at?->format('c'),
                'revoked_at' => $certificate->revoked_at?->format('c'),
            ],
            'badges' => [],
            'is_limited_certificate_view' => true,
        ];
    }

}
