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
        private readonly PortfolioShareIdentityService $portfolioShares
    ) {
    }

    /** @return array{url:string,title:string,hint:string,type:string}|null */
    public function for(Certificate $certificate): ?array
    {
        if ($this->templates->qrDestination(
            (string) $certificate->certificate_text_template_key
        ) === 'portfolio') {
            $user = User::query()->find($certificate->user_id);
            if (!$user) {
                return null;
            }

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
}
