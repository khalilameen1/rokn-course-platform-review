<?php

namespace App\Http\Resources;

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

        return [
            // The printed number, QR target and API identity are one public
            // UUID. The database sequence is not a learner-facing credential.
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
            'status' => $status,
            'verification_level' => $this->verification_level ?? 'completion',
            'verification_label' => ($this->verification_level ?? 'completion') === 'reviewed_project'
                ? 'إتمام الكورس ومراجعة المشروع'
                : 'إتمام الكورس',
            'generated_at' => $this->generated_at?->format('c'),
        ];
    }
}
