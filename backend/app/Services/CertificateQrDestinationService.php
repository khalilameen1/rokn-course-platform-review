<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Models\User;
use App\Support\RoknPublicUrl;

final class CertificateQrDestinationService
{
    public function __construct(
        private readonly CertificateTextTemplateService $templates,
        private readonly PortfolioShareIdentityService $portfolioShares,
        private readonly PortfolioReviewReadService $reviews
    ) {}

    /** @return array{url:string,title:string,hint:string,type:string}|null */
    public function for(Certificate $certificate): ?array
    {
        if ($certificate->certificate_design_version !== null) {
            if (! $certificate->hasCompleteCredentialSnapshot()) {
                return null;
            }

            // Do not re-decide this when sharing, downloading or recovering.
            // The public destination still enforces live revocation/moderation.
            return array_intersect_key($certificate->certificate_qr_snapshot, array_flip(['url', 'title', 'hint', 'type']));
        }

        if ($this->templates->qrDestination(
            (string) $certificate->certificate_text_template_key
        ) === 'portfolio') {
            $user = $certificate->relationLoaded('user')
                ? $certificate->user
                : User::query()->find($certificate->user_id);
            if (! $user) {
                return null;
            }

            // A printed practical-certificate QR is a permanent reference, not
            // an access token. PublicPortfolioService gates this destination
            // and every media request on the currently approved snapshot.
            // Switching it at PDF generation time would permanently remove
            // the practical certificate's portfolio feature while pending.
            $slug = $this->portfolioShares->ensure($user);

            return [
                'url' => RoknPublicUrl::portfolio($slug),
                'title' => 'شاهد الأعمال',
                'hint' => 'امسح الرمز لعرضها',
                'type' => 'portfolio',
            ];
        }

        return [
            'url' => RoknPublicUrl::certificate((string) $certificate->public_id),
            'title' => 'تحقق من الشهادة',
            'hint' => 'امسح الرمز لعرض بياناتها',
            'type' => 'certificate',
        ];
    }

    /**
     * New credentials reference existing approved work, never an empty future
     * portfolio. "Live" is what the public page serves now: a newer draft
     * waiting for review does not take the published version down, so it
     * does not take the QR away either.
     */
    public function forIssuance(User $user, string $publicId): array
    {
        $live = $this->reviews->livePublication($user);
        if ($live !== null && $live['projects'] !== []) {
            return [
                'url' => RoknPublicUrl::portfolio((string) $user->portfolio_slug),
                'title' => 'شاهد الأعمال',
                'hint' => 'امسح الرمز لعرضها',
                'type' => 'portfolio',
            ];
        }

        return [
            'url' => RoknPublicUrl::certificate($publicId),
            'title' => 'تحقق من الشهادة',
            'hint' => 'امسح الرمز لعرض بياناتها',
            'type' => 'certificate',
        ];
    }
}
