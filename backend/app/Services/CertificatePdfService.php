<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Support\DownloadFilename;
use App\Support\UnicodeText;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class CertificatePdfService
{
    /**
     * Wrap the immutable issued image in a page with the exact same aspect
     * ratio. The PDF is a delivery format, not a second certificate renderer,
     * so its pixels can never disagree with the artifact verified by the QR.
     */
    public function download(Certificate $certificate): Response
    {
        if (
            !$certificate->isActiveCredential()
            || !$certificate->hasCompleteCredentialSnapshot()
            || !$certificate->hasStoredArtifact()
        ) {
            throw new \RuntimeException('Certificate is not available for download.');
        }
        $disk = Storage::disk((string) config('certificate.disk', 'public'));
        $path = trim((string) $certificate->image_path);
        $bytes = $path !== '' ? $disk->get($path) : '';
        if ($bytes === '') {
            throw new \RuntimeException('Certificate artifact is empty.');
        }

        $image = @getimagesizefromstring($bytes);
        if (!is_array($image) || (int) $image[0] < 1 || (int) $image[1] < 1) {
            throw new \RuntimeException('Certificate artifact is not a supported image.');
        }

        $temporaryDirectory = (string) config('pdf.tempDir', storage_path('app/temp'));
        if (
            !is_dir($temporaryDirectory)
            && !mkdir($temporaryDirectory, 0750, true)
            && !is_dir($temporaryDirectory)
        ) {
            throw new \RuntimeException('Unable to create the PDF temporary directory.');
        }

        // 300 mm keeps the generated page comfortably inside mPDF limits.
        // The second dimension is derived from the issued image, preventing
        // A4 stretching or white rails around the 4:3 certificate artwork.
        $pageWidth = 300.0;
        $pageHeight = $pageWidth * ((int) $image[1] / (int) $image[0]);
        $pdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [$pageWidth, $pageHeight],
            'margin_left' => 0,
            'margin_right' => 0,
            'margin_top' => 0,
            'margin_bottom' => 0,
            'margin_header' => 0,
            'margin_footer' => 0,
            'tempDir' => $temporaryDirectory,
        ]);
        $pdf->SetDisplayMode('fullpage');
        $pdf->SetAuthor('Rokn');
        $pdf->SetCreator('Rokn');
        $pdf->SetTitle(UnicodeText::clean(
            'شهادة ' . (string) $certificate->course_name,
            false
        ));
        $pdf->SetSubject('شهادة إتمام موثقة من ركن');

        $mime = match ((int) ($image['imagetype'] ?? 0)) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_WEBP => 'image/webp',
            default => 'image/png',
        };
        $source = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $pdf->WriteHTML(sprintf(
            '<style>@page{margin:0}html,body{margin:0;padding:0}img{display:block;width:%1$.4Fmm;height:%2$.4Fmm}</style><img src="%3$s" alt="">',
            $pageWidth,
            $pageHeight,
            $source
        ));

        $contents = $pdf->Output('', Destination::STRING_RETURN);
        $filename = DownloadFilename::safe(
            'شهادة ركن ' . (string) $certificate->holder_name,
            'rokn-certificate',
            'pdf'
        );

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => DownloadFilename::disposition($filename),
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
