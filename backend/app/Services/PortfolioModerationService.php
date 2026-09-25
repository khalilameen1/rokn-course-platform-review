<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One review covers the exact selected profile, projects and media revision. */
final class PortfolioModerationService
{
    public function __construct(
        private readonly PortfolioReviewReadService $reviews,
        private readonly PortfolioShareIdentityService $shareIdentity,
        private readonly PortfolioMediaReadinessService $mediaReadiness
    ) {
    }

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

    /** Persist revocation after content drift; callers opt into this write explicitly. */
    public function reconcile(User $user, ?array $snapshot = null): string
    {
        $status = $this->reviews->status($user, $snapshot);
        if ($user->portfolio_sharing_status === 'approved' && $status === 'pending') {
            // Keep the revision guard: an older reader must not revoke a newer
            // review decision that committed while it was inspecting content.
            self::invalidate((int) $user->id, (int) $user->portfolio_sharing_revision);
            $user->refresh();
        }

        return $status;
    }

    public function reconcileOwnerState(User $user): array
    {
        $current = User::query()->whereKey($user->id)->firstOrFail();
        $status = $this->reconcile($current);
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
            $snapshot = $this->reviews->snapshot($locked);
            abort_unless($snapshot['revision'] === $revision && hash_equals($snapshot['hash'], $hash), 409,
                'تغير المعرض منذ فتح المعاينة أعد تحميله قبل المراجعة');
            if ($decision === 'approved') {
                if (!$locked->active || !$this->shareIdentity->isValidUnlistedSlug((string) $locked->portfolio_slug)) {
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
