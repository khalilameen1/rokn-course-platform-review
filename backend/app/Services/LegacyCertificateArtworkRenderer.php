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
use Intervention\Image\Alignment;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Historical artwork retained only for recovery of pre-editorial credentials.
 *
 * Text is drawn with GD directly, reproducing the Intervention Image 2 font
 * arithmetic (point size, entity escaping, bounding-box alignment) so that
 * recovered credentials stay pixel-identical to the originally issued ones.
 */
final class LegacyCertificateArtworkRenderer
{
    /** @param array{url:string,title:string,hint:string,type:string} $qrDestination */
    public function render(Certificate $certificate, \DateTimeInterface $generatedAt, array $qrDestination): ?string
    {
        try {
            $cfg = config('certificate');
            $positions = $cfg['text_positions'];
            $fontPath = $cfg['font_regular'];

            $templatePath = $cfg['template_path'];
            if (! file_exists($templatePath)) {
                report(new \RuntimeException("Certificate template not found at: {$templatePath}"));

                return null;
            }

            // Load the template
            $img = Image::decodePath($templatePath);
            $canvas = $img->core()->native();
            if (! $canvas instanceof \GdImage) {
                throw new \RuntimeException('Certificate template did not decode to a GD image.');
            }
            $width = $img->width();
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
            $this->drawText(
                $canvas,
                $studentName,
                $placement['x'],
                (int) ($height * $pos['y']),
                $fontPath,
                (int) $pos['size'],
                (string) $pos['color'],
                $placement['align']
            );

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
                $this->drawText(
                    $canvas,
                    $achievementText,
                    $placement['x'],
                    (int) ($height * $achievementPosition['y']),
                    $fontPath,
                    (int) $achievementPosition['size'],
                    (string) $achievementPosition['color'],
                    $placement['align']
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
            $this->drawText(
                $canvas,
                $courseName,
                $placement['x'],
                (int) ($height * $pos['y']),
                $fontPath,
                (int) $pos['size'],
                (string) $pos['color'],
                $placement['align']
            );

            // ----- 4. Certificate ID -----
            // The printed credential must match the public API and QR target;
            // database sequence IDs are implementation details, not credentials.
            $certIdText = (string) $certificate->public_id;
            $pos = $positions['cert_id'];
            $this->drawText(
                $canvas,
                $certIdText,
                (int) ($width * $pos['x']),
                (int) ($height * $pos['y']),
                $fontPath,
                (int) $pos['size'],
                (string) $pos['color'],
                (string) ($pos['align'] ?? 'center')
            );

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
            $this->drawText(
                $canvas,
                $dateText,
                $placement['x'],
                (int) ($height * $pos['y']),
                $fontPath,
                (int) $pos['size'],
                (string) $pos['color'],
                $placement['align']
            );

            // ----- 6. QR code -----
            // Practical certificates lead to the learner's unlisted body of
            // work. Theoretical certificates lead to this exact credential.
            $this->drawQrCaption(
                $canvas,
                $qrDestination,
                (string) $certificate->public_id,
                $positions,
                $fontPath,
                $width,
                $height
            );
            $qrSize = $positions['qr_code']['size'];
            $qrPng = $this->generateQrCode($qrDestination['url'], $qrSize);
            if (! $qrPng) {
                throw new \RuntimeException('Certificate QR code could not be generated.');
            }
            $qrImage = Image::decodeBinary($qrPng);
            // Position the QR so its centre aligns with the configured point
            $qrX = (int) ($width * $positions['qr_code']['x']) - (int) ($qrImage->width() / 2);
            $qrY = (int) ($height * $positions['qr_code']['y']) - (int) ($qrImage->height() / 2);
            $img->insert($qrImage, max(0, $qrX), max(0, $qrY), Alignment::TOP_LEFT);

            return $img->encode(new PngEncoder)->toString();
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * Keep personal names and course titles on one deliberate line without
     * letting unusually long values break the certificate composition.
     *
     * @param  array{size:int,min_size?:int,max_width?:float}  $position
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
        if (! function_exists('imagettfbbox') || ! is_file($fontPath)) {
            return $preferred;
        }

        for ($size = $preferred; $size > $minimum; $size--) {
            $bounds = $this->gdTextBounds($text, $fontPath, $size);
            if ($bounds !== null && $bounds['width'] <= $available) {
                return $size;
            }
        }

        return $minimum;
    }

    /**
     * The historical right/middle correction is based on font-coordinate
     * extrema and becomes unreliable after Arabic glyph shaping. Convert a
     * right edge into an explicit left drawing origin using the exact GD box,
     * then draw left-aligned. This keeps the visual box, not the logical
     * string, inside the main editorial field.
     *
     * @param  array{x:float,size:int,align?:string}  $position
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
        if (! function_exists('imagettfbbox') || ! is_file($fontPath)) {
            return null;
        }

        $box = imagettfbbox(
            $this->pointSize(max(1, $pixelSize)),
            0,
            $fontPath,
            $this->gdText($text)
        );
        if (! is_array($box)) {
            return null;
        }

        $minX = (int) min($box[0], $box[2], $box[4], $box[6]);
        $maxX = (int) max($box[0], $box[2], $box[4], $box[6]);

        return ['width' => $maxX - $minX, 'min_x' => $minX, 'max_x' => $maxX];
    }

    /** Configured pixel sizes were converted to GD points by the historical renderer. */
    private function pointSize(int $pixelSize): int
    {
        return (int) ceil($pixelSize * 0.75);
    }

    /**
     * imagettfbbox()/imagettftext() decode numeric entities. Preserve the
     * literal snapshot and hand GD numeric entities for non-ASCII glyphs.
     */
    private function gdText(string $text): string
    {
        $encoded = preg_replace('/&(#(?:x[a-fA-F0-9]+|[0-9]+);)/', '&#38;\\1', $text);

        return mb_encode_numericentity((string) $encoded, [0x0080, 0xFFFF, 0, 0xFFFF], 'UTF-8');
    }

    /**
     * Draw one text line with the historical alignment corrections: the
     * anchor is shifted by the bounding-box corner(s) that the horizontal
     * and vertical alignment pair selects, then the text is painted at the
     * resulting baseline origin.
     */
    private function drawText(
        \GdImage $canvas,
        string $text,
        int $x,
        int $y,
        string $fontPath,
        int $pixelSize,
        string $color,
        string $align,
        string $valign = 'middle'
    ): void {
        $pointSize = $this->pointSize($pixelSize);
        $encoded = $this->gdText($text);
        $box = imagettfbbox($pointSize, 0, $fontPath, $encoded);
        if (! is_array($box)) {
            throw new \RuntimeException('Unable to measure certificate text.');
        }

        $align = strtolower($align);
        $valign = strtolower($valign);
        switch ($align.'-'.$valign) {
            case 'center-top':
                $x -= (int) round(($box[6] + $box[4]) / 2);
                $y -= (int) round(($box[7] + $box[5]) / 2);
                break;
            case 'right-top':
                $x -= $box[4];
                $y -= $box[5];
                break;
            case 'left-top':
                $x -= $box[6];
                $y -= $box[7];
                break;
            case 'center-center':
            case 'center-middle':
                $x -= (int) round(($box[0] + $box[4]) / 2);
                $y -= (int) round(($box[1] + $box[5]) / 2);
                break;
            case 'right-center':
            case 'right-middle':
                $x -= (int) round(($box[2] + $box[4]) / 2);
                $y -= (int) round(($box[3] + $box[5]) / 2);
                break;
            case 'left-center':
            case 'left-middle':
                $x -= (int) round(($box[0] + $box[6]) / 2);
                $y -= (int) round(($box[1] + $box[7]) / 2);
                break;
            case 'center-bottom':
                $x -= (int) round(($box[0] + $box[2]) / 2);
                $y -= (int) round(($box[1] + $box[3]) / 2);
                break;
            case 'right-bottom':
                $x -= $box[2];
                $y -= $box[3];
                break;
            case 'left-bottom':
                $x -= $box[0];
                $y -= $box[1];
                break;
        }

        imagealphablending($canvas, true);
        if (imagettftext($canvas, $pointSize, 0, $x, $y, $this->colorInt($color), $fontPath, $encoded) === false) {
            throw new \RuntimeException('Unable to draw certificate text.');
        }
    }

    /** Packed opaque GD colour from a "#rgb" or "#rrggbb" configuration value. */
    private function colorInt(string $color): int
    {
        $hex = ltrim(trim($color), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            throw new \RuntimeException("Unsupported certificate colour: {$color}");
        }

        return (int) hexdec($hex);
    }

    private function drawQrCaption(
        \GdImage $canvas,
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
            $this->drawText(
                $canvas,
                $text,
                (int) round($width * $position['x']),
                (int) round($height * $position['y']),
                $fontPath,
                (int) $position['size'],
                (string) $position['color'],
                'center'
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
            $this->drawText(
                $canvas,
                $text,
                (int) round($width * $position['x']),
                (int) round($height * $position['y']),
                $fontPath,
                (int) $position['size'],
                (string) $position['color'],
                'center'
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

            $writer = new PngWriter;
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
        if (! preg_match('/\p{Arabic}/u', $text)) {
            return $text;
        }

        $arabic = new Arabic;
        $positions = $arabic->arIdentify($text);

        for ($i = count($positions) - 1; $i >= 0; $i -= 2) {
            $start = $positions[$i - 1];
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
            $text = substr_replace($text, $shaped, $start, $length);
        }

        return $text;
    }
}
