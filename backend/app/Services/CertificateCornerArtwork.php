<?php

declare(strict_types=1);

namespace App\Services;

/** Deterministic linework from the approved blue certificate concept. No text or logo. */
final class CertificateCornerArtwork
{
    public function draw(\GdImage $canvas): void
    {
        imageantialias($canvas, true);
        try {
            for ($line = 0; $line < 24; $line++) {
                $this->contour($canvas, [
                    [[2260, -80], [2260, -20], [2260, 60], [2260, 110]],
                    [[2260, 110], [2260, 205], [2340, 230], [2390, 250]],
                    [[2390, 250], [2450, 275], [2475, 285], [2515, 340]],
                    [[2515, 340], [2610, 470], [2760, 665], [2900, 845]],
                ], $line * 9, -$line * 3, 0.17, true);
                $bendX = 150 + $line * 8;
                $bendY = 1720 - $line * 3;
                $endX = 120 + $line * 5;
                $endY = 1780 + $line * 6;
                $this->contour($canvas, [
                    [[-80, 1340 - $line * 5], [-80, 1340 - $line * 5],
                        [$bendX, $bendY], [$bendX, $bendY]],
                    [[$bendX, $bendY], [$bendX + 45, $bendY + 60],
                        [$bendX + 60, $endY], [$endX, $endY]],
                    [[$endX, $endY], [65, $endY], [-30, $endY], [-100, $endY]],
                ], 0, 0, 0.10, false);
            }
        } finally {
            imageantialias($canvas, false);
        }
    }

    /** Each segment is a cubic Bezier in the renderer's 2800 x 1900 pixel space. */
    private function contour(\GdImage $canvas, array $segments, int $dx, int $dy,
        float $opacity, bool $fadeDown): void
    {
        foreach ($segments as [$a, $b, $c, $d]) {
            $previous = [$a[0] + $dx, $a[1] + $dy];
            for ($sample = 1; $sample <= 120; $sample++) {
                $t = $sample / 120;
                $u = 1 - $t;
                $x = $u ** 3 * $a[0] + 3 * $u ** 2 * $t * $b[0]
                    + 3 * $u * $t ** 2 * $c[0] + $t ** 3 * $d[0] + $dx;
                $y = $u ** 3 * $a[1] + 3 * $u ** 2 * $t * $b[1]
                    + 3 * $u * $t ** 2 * $c[1] + $t ** 3 * $d[1] + $dy;
                $fade = $fadeDown ? max(0.0, min(1.0, (800 - $y) / 620)) : 1.0;
                $alpha = $opacity * $fade;
                $color = imagecolorallocate($canvas,
                    (int) round(252 + (44 - 252) * $alpha),
                    (int) round(252 + (105 - 252) * $alpha),
                    (int) round(250 + (219 - 250) * $alpha));
                imageline($canvas, (int) round($previous[0]), (int) round($previous[1]),
                    (int) round($x), (int) round($y), $color);
                $previous = [$x, $y];
            }
        }
    }
}
