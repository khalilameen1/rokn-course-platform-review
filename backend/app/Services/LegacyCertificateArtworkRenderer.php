<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Support\RoknPublicUrl;
use App\Support\UnicodeText;
use ArPHP\I18N\Arabic;
use Carbon\CarbonImmutable;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Intervention\Image\Facades\Image;

/** Historical artwork retained only for recovery of pre-editorial credentials. */
final class LegacyCertificateArtworkRenderer
{
    /** @param array{url:string,title:string,hint:string,type:string} $qrDestination */
    public function render(Certificate $certificate, \DateTimeInterface $generatedAt, array $qrDestination): ?string
    {
        try {
            $cfg       = config('certificate');
            $positions = $cfg['text_positions'];
            $fontPath  = $cfg['font_regular'];

            $templatePath = $cfg['template_path'];
            if (!file_exists($templatePath)) {
                report(new \RuntimeException("Certificate template not found at: {$templatePath}"));
                return null;
            }

            // Load the template
            $img    = Image::make($templatePath);
            $width  = $img->width();
            $height = $img->height();

            // ----- 1. Student name -----
            $studentName = UnicodeText::clean($certificate->holder_name, false);
            if ($studentName === '') {
                return null;
            }
            $studentName = $this->shapeIfArabic($studentName);

            $pos = $positions['name'];
            $pos['size'] = $this->fittedFontSize($studentName, $fontPath, $pos, $width);
            $placement = $this->horizontalTextPlacement($studentName, $fontPath, $pos, $width);
            $img->text($studentName, $placement['x'], (int)($height * $pos['y']), function ($font) use ($fontPath, $pos, $placement) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align($placement['align']);
                $font->valign('middle');
            });

            // ----- 2. Achievement wording -----
            // This comes from the immutable certificate snapshot, never from
            // the course's current selection or the live config entry.
            $achievementText = UnicodeText::clean($certificate->certificate_text, false);
            $achievementPosition = $positions['achievement'] ?? null;
            if ($achievementText !== '' && is_array($achievementPosition)) {
                $achievementText = $this->shapeIfArabic($achievementText);
                $achievementPosition['size'] = $this->fittedFontSize(
                    $achievementText,
                    $fontPath,
                    $achievementPosition,
                    $width
                );
                $placement = $this->horizontalTextPlacement(
                    $achievementText,
                    $fontPath,
                    $achievementPosition,
                    $width
                );
                $img->text(
                    $achievementText,
                    $placement['x'],
                    (int) ($height * $achievementPosition['y']),
                    function ($font) use ($fontPath, $achievementPosition, $placement): void {
                        $font->file($fontPath);
                        $font->size($achievementPosition['size']);
                        $font->color($achievementPosition['color']);
                        $font->align($placement['align']);
                        $font->valign('middle');
                    }
                );
            }

            // ----- 3. Course name -----
            $courseName = UnicodeText::clean($certificate->course_name, false);
            if ($courseName === '') {
                return null;
            }
            $courseName = $this->shapeIfArabic($courseName);
            $pos = $positions['course'];
            $pos['size'] = $this->fittedFontSize($courseName, $fontPath, $pos, $width);
            $placement = $this->horizontalTextPlacement($courseName, $fontPath, $pos, $width);
            $img->text($courseName, $placement['x'], (int)($height * $pos['y']), function ($font) use ($fontPath, $pos, $placement) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align($placement['align']);
                $font->valign('middle');
            });

            // ----- 4. Certificate ID -----
            // The printed credential must match the public API and QR target;
            // database sequence IDs are implementation details, not credentials.
            $certIdText = (string) $certificate->public_id;
            $pos = $positions['cert_id'];
            $img->text($certIdText, (int)($width * $pos['x']), (int)($height * $pos['y']), function ($font) use ($fontPath, $pos) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align($pos['align'] ?? 'center');
                $font->valign('middle');
            });

            // ----- 5. Date -----
            $dateText = CarbonImmutable::instance($generatedAt)
                ->locale('ar')
                ->translatedFormat($cfg['date_format']);
            $dateText = strtr($dateText, [
                '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
                '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
            ]);
            $dateText = $this->shapeIfArabic($dateText);
            $pos = $positions['date'];
            $placement = $this->horizontalTextPlacement($dateText, $fontPath, $pos, $width);
            $img->text($dateText, $placement['x'], (int)($height * $pos['y']), function ($font) use ($fontPath, $pos, $placement) {
                $font->file($fontPath);
                $font->size($pos['size']);
                $font->color($pos['color']);
                $font->align($placement['align']);
                $font->valign('middle');
            });

            // ----- 6. QR code -----
            // Practical certificates lead to the learner's unlisted body of
            // work. Theoretical certificates lead to this exact credential.
            $this->drawQrCaption(
                $img,
                $qrDestination,
                (string) $certificate->public_id,
                $positions,
                $fontPath,
                $width,
                $height
            );
            $qrSize     = $positions['qr_code']['size'];
            $qrPng      = $this->generateQrCode($qrDestination['url'], $qrSize);
            if (!$qrPng) {
                throw new \RuntimeException('Certificate QR code could not be generated.');
            }
            $qrImage = Image::make($qrPng);
            // Position the QR so its centre aligns with the configured point
            $qrX = (int)($width  * $positions['qr_code']['x']) - (int)($qrImage->width()  / 2);
            $qrY = (int)($height * $positions['qr_code']['y']) - (int)($qrImage->height() / 2);
            $img->insert($qrImage, 'top-left', max(0, $qrX), max(0, $qrY));

            return (string) $img->encode('png', 95);
        } catch (\Throwable $exception) {
            report($exception);
            return null;
        }
    }

    /**
     * Keep personal names and course titles on one deliberate line without
     * letting unusually long values break the certificate composition.
     *
     * @param array{size:int,min_size?:int,max_width?:float} $position
     */
    private function fittedFontSize(
        string $text,
        string $fontPath,
        array $position,
        int $canvasWidth
    ): int {
        $preferred = max(1, (int) $position['size']);
        $minimum = max(1, min($preferred, (int) ($position['min_size'] ?? $preferred)));
        $available = (int) round(
            $canvasWidth * max(0.1, min(1.0, (float) ($position['max_width'] ?? 1.0)))
        );
        if (!function_exists('imagettfbbox') || !is_file($fontPath)) {
            return $preferred;
        }

        for ($size = $preferred; $size > $minimum; $size--) {
            $bounds = $this->gdTextBounds($text, $fontPath, $size);
            if ($bounds !== null && $bounds['width'] <= $available) return $size;
        }

        return $minimum;
    }

    /**
     * Intervention's GD right/middle correction is based on font-coordinate
     * extrema and becomes unreliable after Arabic glyph shaping. Convert a
     * right edge into an explicit left drawing origin using the exact GD box,
     * then ask Intervention to draw left-aligned. This keeps the visual box,
     * not the logical string, inside the main editorial field.
     *
     * @param array{x:float,size:int,align?:string} $position
     * @return array{x:int,align:string,left:int,right:int}
     */
    private function horizontalTextPlacement(
        string $text,
        string $fontPath,
        array $position,
        int $canvasWidth
    ): array {
        $anchor = (int) round($canvasWidth * (float) $position['x']);
        $align = strtolower((string) ($position['align'] ?? 'center'));
        $bounds = $this->gdTextBounds($text, $fontPath, (int) $position['size']);
        if ($align !== 'right' || $bounds === null) {
            return ['x' => $anchor, 'align' => $align, 'left' => $anchor, 'right' => $anchor];
        }

        $left = $anchor - $bounds['width'];

        return [
            'x' => $left,
            'align' => 'left',
            'left' => $left,
            'right' => $anchor,
        ];
    }

    /** @return array{width:int,min_x:int,max_x:int}|null */
    private function gdTextBounds(string $text, string $fontPath, int $pixelSize): ?array
    {
        if (!function_exists('imagettfbbox') || !is_file($fontPath)) return null;

        // Intervention Image v2 converts configured pixel sizes to GD points.
        $pointSize = (int) ceil(max(1, $pixelSize) * 0.75);
        $encoded = preg_replace('/&(#(?:x[a-fA-F0-9]+|[0-9]+);)/', '&#38;\\1', $text);
        $encoded = mb_encode_numericentity(
            (string) $encoded,
            [0x0080, 0xffff, 0, 0xffff],
            'UTF-8'
        );
        $box = imagettfbbox($pointSize, 0, $fontPath, $encoded);
        if (!is_array($box)) return null;

        $minX = (int) min($box[0], $box[2], $box[4], $box[6]);
        $maxX = (int) max($box[0], $box[2], $box[4], $box[6]);

        return ['width' => $maxX - $minX, 'min_x' => $minX, 'max_x' => $maxX];
    }

    private function drawQrCaption(
        $image,
        array $destination,
        string $certificatePublicId,
        array $positions,
        string $fontPath,
        int $width,
        int $height
    ): void {
        foreach (['qr_title' => 'title', 'qr_hint' => 'hint'] as $positionKey => $textKey) {
            $position = $positions[$positionKey];
            $text = $this->shapeIfArabic($destination[$textKey]);
            $image->text(
                $text,
                (int) round($width * $position['x']),
                (int) round($height * $position['y']),
                function ($font) use ($fontPath, $position): void {
                    $font->file($fontPath);
                    $font->size($position['size']);
                    $font->color($position['color']);
                    $font->align('center');
                    $font->valign('middle');
                }
            );
        }

        $verificationUrl = RoknPublicUrl::certificate($certificatePublicId);
        $verificationParts = parse_url($verificationUrl);
        $verificationLines = [
            'verification_host' => (string) ($verificationParts['host'] ?? ''),
            'verification_path' => (string) ($verificationParts['path'] ?? ''),
        ];
        foreach ($verificationLines as $positionKey => $text) {
            if ($text === '') {
                continue;
            }
            $position = $positions[$positionKey];
            $position['size'] = $this->fittedFontSize(
                $text,
                $fontPath,
                $position,
                $width
            );
            $image->text(
                $text,
                (int) round($width * $position['x']),
                (int) round($height * $position['y']),
                function ($font) use ($fontPath, $position): void {
                    $font->file($fontPath);
                    $font->size($position['size']);
                    $font->color($position['color']);
                    $font->align('center');
                    $font->valign('middle');
                }
            );
        }
    }

    private function generateQrCode(string $url, int $size = 100): ?string
    {
        try {
            $qrCode = new QrCode(
                data: $url,
                encoding: new Encoding('UTF-8'),
                errorCorrectionLevel: ErrorCorrectionLevel::High,
                size: $size,
                margin: 5,
                foregroundColor: new Color(0, 0, 0),
                backgroundColor: new Color(255, 255, 255),
            );

            $writer = new PngWriter();
            $result = $writer->write($qrCode);

            return $result->getString();
        } catch (\Exception $e) {
            report($e);
            return null;
        }
    }

    /* ------------------------------------------------------------------
     * Arabic text shaping
     * ----------------------------------------------------------------*/

    /**
     * If the text contains Arabic characters, apply glyph shaping so
     * that GD / imagettftext renders them correctly (joined, RTL).
     */
    private function shapeIfArabic(string $text): string
    {
        $text = UnicodeText::clean($text, false);
        if (!preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        $arabic   = new Arabic();
        $positions = $arabic->arIdentify($text);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $start  = $positions[$i - 1];
            $length = $positions[$i] - $start;
            $substr = substr($text, $start, $length);
            // ArPHP defaults to wrapping after 50 characters, which silently
            // inserts a newline into long names or course titles. Certificate
            // wrapping is controlled by our measured layout, so shaping must
            // never mutate a one-line field into multiple lines.
            $shaped = $arabic->utf8Glyphs(
                $substr,
                max(1, mb_strlen($substr, 'UTF-8') + 1)
            );
            $text   = substr_replace($text, $shaped, $start, $length);
        }

        return $text;
    }
}
