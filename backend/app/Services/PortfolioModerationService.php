<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One review covers the exact selected profile, projects and media revision. */
final class PortfolioModerationService
{
    public const PROFILE_FIELDS = [
        'name', 'name_ar', 'name_en', 'profile_image', 'role', 'active',
        'portfolio_slug', 'portfolio_headline', 'portfolio_location',
        'portfolio_skills', 'portfolio_links',
    ];

    public static function invalidate(int $userId, ?int $expectedRevision = null): void
    {
        $query = DB::table('users')->where('id', $userId);
        if ($expectedRevision !== null) {
            $query->where('portfolio_sharing_revision', $expectedRevision);
        }
        $query->update([
            'portfolio_sharing_status' => 'pending',
            'portfolio_sharing_revision' => DB::raw('portfolio_sharing_revision + 1'),
            'portfolio_approved_hash' => null,
            'portfolio_sharing_rejection_reason' => null,
        ]);
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
            || !app(PortfolioShareIdentityService::class)->isValidUnlistedSlug((string) $user->portfolio_slug)
            || !hash_equals((string) $user->portfolio_approved_hash, $snapshot['hash'])) {
            // Also fail closed for out-of-band writes (for example course title edits).
            self::invalidate((int) $user->id, (int) $user->portfolio_sharing_revision);
            $user->refresh();
            return 'pending';
        }

        return 'approved';
    }

    public function ownerState(User $user): array
    {
        $current = User::query()->whereKey($user->id)->firstOrFail();
        $status = $this->status($current);
        return [
            'sharing_status' => $status,
            'sharing_revision' => (int) $current->portfolio_sharing_revision,
            'sharing_rejection_reason' => $status === 'rejected'
                ? $current->portfolio_sharing_rejection_reason : null,
            'sharing_suspended' => $current->portfolio_sharing_suspended_at !== null,
        ];
    }

    public function decide(User $owner, User $reviewer, int $revision, string $hash, string $decision, ?string $reason): void
    {
        abort_unless($reviewer->role === 'admin', 403);
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);

        DB::transaction(function () use ($owner, $reviewer, $revision, $hash, $decision, $reason): void {
            $locked = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($locked);
            abort_unless($snapshot['revision'] === $revision && hash_equals($snapshot['hash'], $hash), 409,
                'تغير المعرض منذ فتح المعاينة أعد تحميله قبل المراجعة');
            if ($decision === 'approved') {
                if (!$locked->active || !app(PortfolioShareIdentityService::class)->isValidUnlistedSlug((string) $locked->portfolio_slug)) {
                    throw ValidationException::withMessages(['decision' => 'يلزم حساب نشط ورابط معرض صالح قبل الاعتماد']);
                }
                foreach ($snapshot['items'] as $item) {
                    foreach ($item->mediaFiles as $media) {
                        $presentation = app(PortfolioMediaReadinessService::class)->presentation($media, true);
                        if (($presentation['status'] ?? null) !== 'ready') {
                            throw ValidationException::withMessages([
                                'decision' => 'انتظر جاهزية جميع الملفات ثم راجع المعرض قبل الاعتماد',
                            ]);
                        }
                    }
                }
            } elseif (trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'اكتب سبب الرفض ليظهر لصاحب المعرض']);
            }

            $locked->forceFill([
                'portfolio_sharing_status' => $decision,
                // Consumes the review form as well as guarding content edits.
                'portfolio_sharing_revision' => $revision + 1,
                'portfolio_approved_hash' => $decision === 'approved' ? $hash : null,
                'portfolio_reviewed_at' => now(),
                'portfolio_reviewed_by' => $reviewer->id,
                'portfolio_sharing_rejection_reason' => $decision === 'rejected' ? trim((string) $reason) : null,
            ])->save();
        });
    }
}
