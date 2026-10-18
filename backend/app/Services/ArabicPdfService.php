<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DownloadFilename;
use App\Support\UnicodeText;
use Illuminate\Http\Response;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class ArabicPdfService
{
    /**
     * Render a trusted Blade view with an embedded Arabic font and return it
     * as an attachment. Keeping this wrapper application-owned avoids the
     * abandoned LaravelPdf package while retaining mPDF's Arabic shaping.
     *
     * @param  array<string, mixed>  $data
     * @param  array{author?:string,creator?:string,subject?:string,keywords?:string}  $metadata
     */
    public function downloadView(
        string $view,
        array $data,
        string $filename,
        array $metadata = []
    ): Response {
        $temporaryDirectory = (string) config('pdf.tempDir', storage_path('app/temp'));
        if (! is_dir($temporaryDirectory) && ! mkdir($temporaryDirectory, 0750, true) && ! is_dir($temporaryDirectory)) {
            throw new \RuntimeException('Unable to create the PDF temporary directory.');
        }

        $defaultConfig = (new ConfigVariables)->getDefaults();
        $defaultFonts = (new FontVariables)->getDefaults();
        $fontDirectory = rtrim((string) config('pdf.font_path', resource_path('fonts')), '/\\');

        $pdf = new Mpdf([
            'mode' => (string) config('pdf.mode', 'utf-8'),
            'format' => (string) config('pdf.format', 'A4'),
            'orientation' => (string) config('pdf.orientation', 'P'),
            'default_font_size' => (float) config('pdf.default_font_size', 12),
            'default_font' => (string) config('pdf.default_font', 'cairo'),
            'margin_left' => (float) config('pdf.margin_left', 15),
            'margin_right' => (float) config('pdf.margin_right', 15),
            'margin_top' => (float) config('pdf.margin_top', 15),
            'margin_bottom' => (float) config('pdf.margin_bottom', 15),
            'margin_header' => (float) config('pdf.margin_header', 5),
            'margin_footer' => (float) config('pdf.margin_footer', 10),
            'tempDir' => $temporaryDirectory,
            'fontDir' => array_values(array_unique(array_merge(
                $defaultConfig['fontDir'],
                [$fontDirectory]
            ))),
            'fontdata' => array_merge(
                $defaultFonts['fontdata'],
                (array) config('pdf.font_data', [])
            ),
        ]);

        $pdf->SetDirectionality('rtl');
        $pdf->SetDisplayMode((string) config('pdf.display_mode', 'fullpage'));
        $pdf->SetAuthor(UnicodeText::clean($metadata['author'] ?? config('pdf.author', ''), false));
        $pdf->SetCreator(UnicodeText::clean($metadata['creator'] ?? config('pdf.creator', 'Rokn'), false));
        $pdf->SetSubject(UnicodeText::clean($metadata['subject'] ?? config('pdf.subject', ''), false));
        $pdf->SetKeywords(UnicodeText::clean($metadata['keywords'] ?? config('pdf.keywords', ''), false));
        $pdf->WriteHTML(view($view, $data)->render());

        $contents = $pdf->Output('', Destination::STRING_RETURN);
        $safeFilename = DownloadFilename::safe($filename, 'rokn-export', 'pdf');
        $disposition = DownloadFilename::disposition($safeFilename);

        return response($contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
