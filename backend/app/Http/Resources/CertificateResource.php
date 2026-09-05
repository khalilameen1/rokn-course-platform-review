<?php

namespace App\Http\Resources;

use App\Services\CertificateQrDestinationService;
use App\Support\RoknPublicUrl;
use Illuminate\Http\Resources\Json\JsonResource;

class CertificateResource extends JsonResource
{
    public function toArray($request)
    {
        $revoked = $this->isRevokedCredential();
        $artifactReady = !$revoked
            && $this->hasCompleteCredentialSnapshot()
            && $this->hasStoredArtifact();
        $status = $revoked
            ? 'revoked'
            : ($artifactReady ? 'active' : 'pending');
        $holderName = trim((string) $this->holder_name);
        $courseName = trim((string) $this->course_name);
        $textTemplateKey = trim((string) $this->certificate_text_template_key);
        $certificateText = trim((string) $this->certificate_text);
        $publicId = trim((string) $this->public_id);
        $verificationUrl = $publicId !== '' ? RoknPublicUrl::certificate($publicId) : '';
        $qrDestination = !$revoked
            ? app(CertificateQrDestinationService::class)->for($this->resource)
            : null;

        return [
            // The printed number, verification route and API identity share
            // one public UUID. QR navigation is the separate server-owned
            // destination below because practical certificates open works.
            'public_id' => $publicId,
            'course_id' => (int) $this->course_id,
            'holder_name' => $holderName !== '' ? $holderName : null,
            'course_name' => $courseName !== '' ? $courseName : null,
            'certificate_text_template_key' => $textTemplateKey !== '' ? $textTemplateKey : null,
            'certificate_text' => $certificateText !== '' ? $certificateText : null,
            'certificate_url' => $artifactReady && $publicId !== ''
                ? RoknPublicUrl::certificateArtifact($publicId)
                : '',
            'certificate_pdf_url' => $artifactReady && $publicId !== ''
                ? RoknPublicUrl::certificatePdf($publicId)
                : '',
            'verification_url' => $verificationUrl,
            'qr_destination' => $qrDestination,
            'status' => $status,
            'verification_level' => $this->verification_level ?? 'completion',
            'verification_label' => ($this->verification_level ?? 'completion') === 'reviewed_project'
                ? 'إتمام الكورس ومراجعة المشروع'
                : 'إتمام الكورس',
            'generated_at' => $this->generated_at?->format('c'),
        ];
    }
}
