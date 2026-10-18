<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PortfolioPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * One review covers the exact selected profile, projects and media revision.
 * An approval also becomes the publication that stays live while later
 * drafts wait for review (see PortfolioReviewReadService).
 */
final class PortfolioModerationService
{
    public function __construct(
        private readonly PortfolioReviewReadService $reviews,
        private readonly PortfolioShareIdentityService $shareIdentity,
        private readonly PortfolioMediaReadinessService $mediaReadiness
    ) {}

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

    /**
     * Model hooks call this before a change moves the draft. A portfolio that
     * was approved before publications were stored keeps its approved content
     * live instead of disappearing on the first edit.
     */
    public static function preserveLegacyPublication(int $userId): void
    {
        if ($userId < 1) {
            return;
        }
        try {
            $state = DB::table('users')->where('id', $userId)
                ->first(['portfolio_sharing_status', 'portfolio_approved_hash']);
            if (! $state || $state->portfolio_sharing_status !== 'approved' || ! $state->portfolio_approved_hash
                || PortfolioPublication::query()->where('user_id', $userId)->exists()) {
                return;
            }
            $user = User::query()->find($userId);
            if ($user) {
                app(self::class)->ensurePublication($user);
            }
        } catch (Throwable $exception) {
            // Never block the owner's own edit; the stored approval simply
            // stops matching and the portfolio waits for review as before.
            report($exception);
        }
    }

    /** Store a pre-publication approval as the publication while it still matches. */
    public function ensurePublication(User $user, ?array $snapshot = null): void
    {
        if ($user->portfolio_sharing_status !== 'approved' || ! $user->portfolio_approved_hash
            || PortfolioPublication::query()->where('user_id', $user->id)->exists()) {
            return;
        }
        $snapshot ??= $this->reviews->snapshot($user);
        if (hash_equals((string) $user->portfolio_approved_hash, $snapshot['hash'])) {
            $this->publish((int) $user->id, $snapshot, $user->portfolio_reviewed_by ? (int) $user->portfolio_reviewed_by : null);
        }
    }

    /** Persist revocation after content drift; callers opt into this write explicitly. */
    public function reconcile(User $user, ?array $snapshot = null): string
    {
        $snapshot ??= $this->reviews->snapshot($user);
        $this->ensurePublication($user, $snapshot);
        $status = $this->reviews->status($user, $snapshot);
        if ($user->portfolio_sharing_status === 'approved' && $status === 'pending') {
            // Keep the revision guard: an older reader must not revoke a newer
            // review decision that committed while it was inspecting content.
            self::invalidate((int) $user->id, (int) $user->portfolio_sharing_revision);
            $user->refresh();
        } elseif ($status === 'approved' && ($user->portfolio_sharing_status !== 'approved'
            || ! hash_equals((string) $user->portfolio_approved_hash, $snapshot['hash']))) {
            // The draft equals the live publication (only removals since the
            // last approval): there is nothing left in the review queue.
            DB::table('users')->where('id', $user->id)
                ->where('portfolio_sharing_revision', (int) $user->portfolio_sharing_revision)
                ->update([
                    'portfolio_sharing_status' => 'approved',
                    'portfolio_approved_hash' => $snapshot['hash'],
                    'portfolio_sharing_rejection_reason' => null,
                ]);
            $user->refresh();
        }

        return $status;
    }

    public function reconcileOwnerState(User $user): array
    {
        $current = User::query()->whereKey($user->id)->firstOrFail();
        $snapshot = $this->reviews->snapshot($current);
        $status = $this->reconcile($current, $snapshot);
        $live = $this->reviews->livePublication($current);
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

    public function decide(User $owner, User $reviewer, int $revision, string $hash, string $decision, ?string $reason): void
    {
        abort_unless($reviewer->role === 'admin', 403);
        abort_unless(in_array($decision, ['approved', 'rejected'], true), 422);

        DB::transaction(function () use ($owner, $reviewer, $revision, $hash, $decision, $reason): void {
            $locked = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->reviews->snapshot($locked);
            abort_unless($snapshot['revision'] === $revision && hash_equals($snapshot['hash'], $hash), 409,
                'تغير المعرض منذ فتح المعاينة أعد تحميله قبل المراجعة');
            if ($decision === 'approved') {
                if (! $locked->active || ! $this->shareIdentity->isValidUnlistedSlug((string) $locked->portfolio_slug)) {
                    throw ValidationException::withMessages(['decision' => 'يلزم حساب نشط ورابط معرض صالح قبل الاعتماد']);
                }
                foreach ($snapshot['items'] as $item) {
                    foreach ($item->mediaFiles as $media) {
                        $presentation = $this->mediaReadiness->presentation($media, true);
                        if (($presentation['status'] ?? null) !== 'ready') {
                            throw ValidationException::withMessages([
                                'decision' => 'انتظر جاهزية جميع الملفات ثم راجع المعرض قبل الاعتماد',
                            ]);
                        }
                    }
                }
                $this->publish((int) $locked->id, $snapshot, (int) $reviewer->id);
            } elseif (trim((string) $reason) === '') {
                throw ValidationException::withMessages(['reason' => 'اكتب سبب الرفض ليظهر لصاحب المعرض']);
            } else {
                $this->ensurePublication($locked, $snapshot);
                $live = $this->reviews->publication($locked, $snapshot);
                // Rejecting a draft keeps the previous approval live (the
                // store-review model); rejecting the live content itself
                // takes it down.
                if ($live && ($live['stored'] ?? false) && $this->reviews->effectiveHash($locked, $live['content']) === $hash) {
                    PortfolioPublication::query()->where('user_id', $locked->id)->delete();
                }
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

    private function publish(int $userId, array $snapshot, ?int $approvedBy): void
    {
        PortfolioPublication::query()->updateOrCreate(['user_id' => $userId], [
            'revision' => (int) $snapshot['revision'],
            'content_hash' => $snapshot['hash'],
            'content' => json_encode($snapshot['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'approved_by' => $approvedBy,
            'published_at' => now(),
        ]);
    }
}
