<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CertificateCornerArtwork;
use PHPUnit\Framework\TestCase;

final class CertificateCornerArtworkTest extends TestCase
{
    public function test_decoration_is_confined_to_corners_and_leaves_content_and_qr_plain(): void
    {
        $image = imagecreatetruecolor(2800, 1900);
        self::assertInstanceOf(\GdImage::class, $image);
        $paper = 0xfcfcfa;
        imagefill($image, 0, 0, $paper);
        try {
            (new CertificateCornerArtwork())->draw($image);
            $upper = $lower = 0;
            $escaped = 0;
            for ($y = 0; $y < 1900; $y++) {
                for ($x = 0; $x < 2800; $x++) {
                    if ((imagecolorat($image, $x, $y) & 0xffffff) === $paper) continue;
                    if ($x >= 2258 && $y < 800) $upper++;
                    elseif ($x < 400 && $y > 1250) $lower++;
                    else $escaped++;
                }
            }
            self::assertGreaterThan(1000, $upper);
            self::assertGreaterThan(1000, $lower);
            self::assertSame(0, $escaped, 'Background must not overlap names, signature or QR quiet zone.');
        } finally {
            imagedestroy($image);
        }
    }
}
