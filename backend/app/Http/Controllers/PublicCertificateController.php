<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Services\CertificatePdfService;
use App\Services\PublicPortfolioService;
use App\Support\DownloadFilename;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublicCertificateController extends Controller
{
    public function show(string $publicId, PublicPortfolioService $service)
    {
        $portfolio = $service->findCredential($publicId);
        abort_if(!$portfolio, 404);

        return response(view('portfolio.public', compact('portfolio')))
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** Serve the artifact only while the public credential remains active. */
    public function artifact(string $publicId): StreamedResponse
    {
        $certificate = Certificate::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();
        abort_unless(
            $certificate
                && $certificate->hasCompleteCredentialSnapshot()
                && $certificate->hasStoredArtifact(),
            404
        );

        $disk = Storage::disk((string) config('certificate.disk', 'public'));
        $path = (string) $certificate->image_path;
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $extension = in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)
            ? $extension
            : 'png';
        $name = DownloadFilename::safe(
            'شهادة ركن ' . (string) $certificate->holder_name,
            'rokn-certificate',
            $extension
        );

        try {
            $mime = $disk->mimeType($path) ?: 'image/png';
        } catch (\Throwable) {
            $mime = 'image/png';
        }

        return $disk->response($path, $name, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ], 'inline');
    }

    /** Download the issued artwork as a full-bleed PDF without re-rendering it. */
    public function download(
        string $publicId,
        CertificatePdfService $pdf
    ): \Illuminate\Http\Response {
        $certificate = Certificate::query()
            ->where('public_id', $publicId)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->first();
        abort_unless(
            $certificate
                && $certificate->hasCompleteCredentialSnapshot()
                && $certificate->hasStoredArtifact(),
            404
        );

        return $pdf->download($certificate);
    }
}
