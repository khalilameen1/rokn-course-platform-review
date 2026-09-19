<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Support\UnicodeText;
use ArPHP\I18N\Arabic;
use Carbon\CarbonImmutable;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * The approved editorial artwork, shared by authoring preview and issuance.
 * Pure rendering: it never queries learners, creates credentials or writes files.
 * Keep this version's assets and coordinates stable for artifact recovery.
 */
final class CertificateArtworkRenderer
{
    public const VERSION = 'editorial_v1';
    public const WIDTH = 2800;
    public const HEIGHT = 1900;
    private const SCALE = 2;
    private const INK = [16, 28, 45];
    private const SECONDARY = [102, 112, 123];
    private const PAPER = [252, 252, 250];

    /** @param array{url:string,title:string,hint:string,type:string} $destination */
    public function render(Certificate $certificate, array $destination): string
    {
        if ($certificate->certificate_design_version !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported certificate artwork version.');
        }
        foreach (['holder_name', 'course_name', 'certificate_text', 'public_id'] as $field) {
            if (UnicodeText::clean($certificate->{$field}, false) === '') {
                throw new \InvalidArgumentException('Incomplete certificate artwork snapshot.');
            }
        }
        if (!$certificate->generated_at instanceof \DateTimeInterface) {
            throw new \InvalidArgumentException('Missing certificate issue date.');
        }
        foreach (['type', 'url', 'title', 'hint'] as $field) {
            if (!isset($destination[$field]) || !is_string($destination[$field]) || trim($destination[$field]) === '') {
                throw new \InvalidArgumentException('Incomplete certificate QR destination.');
            }
        }
        if (!in_array($destination['type'], ['certificate', 'portfolio'], true)
            || filter_var($destination['url'], FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('Invalid certificate QR destination.');
        }

        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (!$canvas) throw new \RuntimeException('Unable to create certificate canvas.');
        try {
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, ...self::PAPER));
            $this->placeAsset($canvas, 'wordmark.png', 700, 102, 132, 43.34, true);

            $lines = $this->statement($certificate);
            $height = array_sum(array_column($lines, 'height')) + array_sum(array_column($lines, 'after'));
            $top = 222 + (330 - $height) / 2;
            foreach ($lines as $line) {
                $this->text($canvas, $line['text'], 700, $top + $line['height'] / 2,
                    $line['size'], $line['weight'], $line['color'], 1176);
                $top += $line['height'] + $line['after'];
            }

            $rule = imagecolorallocate($canvas, 220, 224, 227);
            imageline($canvas, 224, 1256, 2576, 1256, $rule);
            $this->text($canvas, 'تاريخ الإتمام', 1092, 669, 16, 'Regular', self::SECONDARY);
            $this->text($canvas, 'CEO', 700, 669, 15, 'Medium', self::SECONDARY);
            $this->text($canvas, $destination['title'], 308, 669, 16, 'Regular', self::SECONDARY, 360);

            // GD has no bidi engine. Give it the final visual order so the
            // day and year remain readable instead of becoming ٥١ and ٦٢٠٢.
            $this->text($canvas, $this->visualDate($certificate->generated_at),
                1092, 749, 22, 'Medium', self::INK, 360, true);
            $this->placeAsset($canvas, 'signature.png', 700, 749, 252, 84);
            $this->qr($canvas, $destination['url'], 308, 749);

            // One centred identifier group, with the label to its right in RTL.
            $id = (string) $certificate->public_id;
            $label = 'رقم الشهادة';
            $labelWidth = $this->measure($this->shape($label), 10, 'Regular')['width'] / self::SCALE;
            $idWidth = $this->measure($id, 10, 'Regular')['width'] / self::SCALE;
            $left = 700 - ($idWidth + 12 + $labelWidth) / 2;
            $this->text($canvas, $id, $left + $idWidth / 2, 866, 10, 'Regular', self::SECONDARY);
            $this->text($canvas, $label, $left + $idWidth + 12 + $labelWidth / 2, 866, 10, 'Regular', self::SECONDARY);

            ob_start();
            try {
                if (!imagepng($canvas, null, 6)) throw new \RuntimeException('Unable to encode certificate.');
                return (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
        } finally {
            imagedestroy($canvas);
        }
    }

    /** @return list<array{text:string,size:int,weight:string,color:array,height:float,after:int}> */
    private function statement(Certificate $certificate): array
    {
        $completion = UnicodeText::clean($certificate->certificate_completion_text, false);
        $lines = [
            ['text'=>'تشهد رُكن أن','size'=>24,'weight'=>'Regular','color'=>self::SECONDARY,'height'=>37.2,'after'=>8],
            ['text'=>(string) $certificate->holder_name,'size'=>66,'weight'=>'SemiBold','color'=>self::INK,'height'=>99.0,'after'=>14],
            ['text'=>(string) $certificate->certificate_text,'size'=>23,'weight'=>'Regular','color'=>self::SECONDARY,'height'=>36.8,'after'=>2],
            ['text'=>(string) $certificate->course_name,'size'=>42,'weight'=>'SemiBold','color'=>self::INK,'height'=>65.1,'after'=>$completion === '' ? 0 : 8],
        ];
        if ($completion !== '') {
            $lines[] = ['text'=>$completion,'size'=>22,'weight'=>'Regular','color'=>self::SECONDARY,'height'=>35.2,'after'=>0];
        }
        return $lines;
    }

    /** Draw around the real glyph bounds, not GD's inconsistent RTL alignment. */
    private function text(\GdImage $canvas, string $value, float $x, float $y, int $size,
        string $weight, array $color, int $maxWidth = 1176, bool $alreadyShaped = false): void
    {
        $text = $alreadyShaped ? $value : $this->shape($value);
        // GD decodes numeric entities in both imagettfbbox and imagettftext.
        // Preserve the literal snapshot, as Intervention\Image\Gd\Font does:
        // an authored "&#65;" must not silently become "A" on the credential.
        $text = preg_replace('/&(#(?:x[a-fA-F0-9]+|[0-9]+);)/', '&#38;\\1', $text) ?? $text;
        $bounds = $this->measure($text, $size, $weight);
        while ($bounds['width'] > $maxWidth * self::SCALE && $size > 10) {
            $bounds = $this->measure($text, --$size, $weight);
        }
        if ($bounds['width'] > $maxWidth * self::SCALE) {
            throw new \RuntimeException('Certificate text exceeds its layout bounds.');
        }
        $baselineX = (int) round($x * self::SCALE - ($bounds['min_x'] + $bounds['max_x']) / 2);
        $baselineY = (int) round($y * self::SCALE - ($bounds['min_y'] + $bounds['max_y']) / 2);
        if (imagettftext($canvas, $size * self::SCALE * .75, 0, $baselineX, $baselineY,
            imagecolorallocate($canvas, ...$color), $this->font($weight), $text) === false) {
            throw new \RuntimeException('Unable to draw certificate text.');
        }
    }

    /** @return array{width:int,min_x:int,max_x:int,min_y:int,max_y:int} */
    private function measure(string $text, int $size, string $weight): array
    {
        $box = imagettfbbox($size * self::SCALE * .75, 0, $this->font($weight), $text);
        if ($box === false) throw new \RuntimeException('Unable to measure certificate text.');
        $xs = [$box[0],$box[2],$box[4],$box[6]];
        $ys = [$box[1],$box[3],$box[5],$box[7]];
        return ['width'=>max($xs)-min($xs),'min_x'=>min($xs),'max_x'=>max($xs),'min_y'=>min($ys),'max_y'=>max($ys)];
    }

    private function font(string $weight): string
    {
        $path = $this->asset('Cairo-' . $weight . '.ttf');
        if (!is_file($path)) throw new \RuntimeException('Certificate font is unavailable.');
        return $path;
    }

    private function asset(string $file): string
    {
        return resource_path('certificates/editorial-v1/' . $file);
    }

    private function shape(string $text): string
    {
        $text = UnicodeText::clean($text, false);
        if (!preg_match('/\p{Arabic}/u', $text)) return $text;
        $arabic = new Arabic();
        $positions = $arabic->arIdentify($text);
        for ($i = count($positions) - 1; $i >= 1; $i -= 2) {
            $start = $positions[$i - 1];
            $length = $positions[$i] - $start;
            $part = substr($text, $start, $length);
            $text = substr_replace($text, $arabic->utf8Glyphs($part, mb_strlen($part, 'UTF-8') + 1), $start, $length);
        }
        return $text;
    }

    private function visualDate(\DateTimeInterface $issuedAt): string
    {
        $date = CarbonImmutable::instance($issuedAt)->locale('ar');
        $digits = static fn (string $value): string => strtr($value, [
            '0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤',
            '5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩',
        ]);

        // imagettftext paints left-to-right. Paint the RTL components from
        // their visual left edge: year, shaped month, then day.
        return $digits($date->format('Y')).' '
            .$this->shape($date->translatedFormat('F')).' '
            .$digits($date->format('j'));
    }

    private function placeAsset(\GdImage $canvas, string $file, float $x, float $y,
        float $width, float $height, bool $tint = false): void
    {
        $asset = imagecreatefrompng($this->asset($file));
        if (!$asset) throw new \RuntimeException('Certificate brand asset is unavailable.');
        try {
            if ($tint) {
                $tinted = $this->solidAsset($asset, self::INK);
                imagedestroy($asset);
                $asset = $tinted;
            }
            imagecopyresampled($canvas, $asset,
                (int) round(($x - $width / 2) * self::SCALE), (int) round(($y - $height / 2) * self::SCALE),
                0, 0, (int) round($width * self::SCALE), (int) round($height * self::SCALE), imagesx($asset), imagesy($asset));
        } finally {
            imagedestroy($asset);
        }
    }

    /** Preserve the original alpha mask while using the exact brand ink. */
    private function solidAsset(\GdImage $source, array $color): \GdImage
    {
        $result = imagecreatetruecolor(imagesx($source), imagesy($source));
        if (!$result) throw new \RuntimeException('Unable to tint certificate brand asset.');
        imagealphablending($result, false);
        imagesavealpha($result, true);
        $palette = [];
        for ($y = 0; $y < imagesy($source); $y++) {
            for ($x = 0; $x < imagesx($source); $x++) {
                $alpha = imagecolorsforindex($source, imagecolorat($source, $x, $y))['alpha'];
                $palette[$alpha] ??= imagecolorallocatealpha($result, ...$color, $alpha);
                imagesetpixel($result, $x, $y, $palette[$alpha]);
            }
        }
        imagealphablending($result, true);
        return $result;
    }

    private function qr(\GdImage $canvas, string $url, int $x, int $y): void
    {
        $code = new QrCode(data: $url, errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 184, margin: 16, foregroundColor: new Color(...self::INK), backgroundColor: new Color(...self::PAPER));
        $qr = imagecreatefromstring((new PngWriter())->write($code)->getString());
        if (!$qr) throw new \RuntimeException('Unable to draw certificate QR.');
        try {
            imagecopy($canvas, $qr, $x * self::SCALE - (int) (imagesx($qr) / 2),
                $y * self::SCALE - (int) (imagesy($qr) / 2), 0, 0, imagesx($qr), imagesy($qr));
        } finally {
            imagedestroy($qr);
        }
    }
}
