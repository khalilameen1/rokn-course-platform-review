<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Certificate;
use App\Services\CertificateArtworkRenderer;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CertificateArtworkRendererTest extends TestCase
{
    private const PAPER = 0xfcfcfa;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(extension_loaded('gd'), 'Certificate artwork requires GD.');
        self::assertTrue((bool) (gd_info()['FreeType Support'] ?? false));
        DB::connection()->beforeExecuting(static function (): void {
            throw new \LogicException('Certificate artwork must not execute SQL.');
        });
    }

    public function test_artwork_contains_the_real_assets_on_the_approved_canvas(): void
    {
        foreach (['wordmark.png', 'signature.png', 'Cairo-Regular.ttf', 'Cairo-Medium.ttf', 'Cairo-SemiBold.ttf'] as $asset) {
            $path = resource_path('certificates/editorial-v1/'.$asset);
            self::assertFileIsReadable($path);
            self::assertGreaterThan(1000, filesize($path));
        }

        $certificate = $this->certificate();
        $attributes = $certificate->getAttributes();
        $bytes = app(CertificateArtworkRenderer::class)->render($certificate, $this->destination());
        $info = getimagesizefromstring($bytes);
        self::assertIsArray($info);
        self::assertSame([2800, 1900, IMAGETYPE_PNG], array_slice($info, 0, 3));
        self::assertSame($attributes, $certificate->getAttributes(), 'Rendering must not rewrite its snapshot.');
        self::assertFalse($certificate->exists, 'Rendering is not issuance.');

        $image = $this->decode($bytes);
        try {
            foreach ([[0, 0], [2799, 0], [2799, 1899], [1400, 1200]] as [$x, $y]) {
                self::assertSame(self::PAPER, imagecolorat($image, $x, $y) & 0xffffff);
            }
            self::assertGreaterThan(100, $this->inkPixels($image, 1268, 224, 264, 88), 'The original wordmark must be visible.');
            self::assertGreaterThan(1000, $this->colorPixels($image, 1268, 224, 264, 88, 0x2c69db), 'The wordmark must use Rokn brand blue.');
            self::assertGreaterThan(80, $this->inkPixels($image, 1148, 1414, 504, 168), 'The Arabic signature must be visible.');
            self::assertGreaterThan(500, $this->inkPixels($image, 500, 1380, 240, 240), 'The QR must not be empty.');
            self::assertGreaterThan(80, $this->inkPixels($image, 900, 1700, 1000, 64), 'The credential identifier must be visible.');
            self::assertSame(0xb9c1c9, imagecolorat($image, 1400, 1256) & 0xffffff);
        } finally {
            imagedestroy($image);
        }
    }

    public function test_previous_editorial_version_keeps_its_original_layout(): void
    {
        $renderer = app(CertificateArtworkRenderer::class);
        $old = $this->decode($renderer->render($this->certificate([
            'certificate_design_version' => 'editorial_v1',
        ]), $this->destination()));
        $current = $this->decode($renderer->render($this->certificate(), $this->destination()));
        try {
            self::assertGreaterThan(1000, $this->colorPixels($old, 1268, 160, 264, 88, 0x101c2d));
            self::assertSame(0xdce0e3, imagecolorat($old, 1400, 1256) & 0xffffff);
            self::assertGreaterThan(
                $this->inkPixels($old, 700, 1700, 1400, 64),
                $this->inkPixels($current, 700, 1700, 1400, 64),
                'The new identifier must be larger without changing old credentials.'
            );
            self::assertSame(
                $this->regionHash($old, 400, 1300, 2400, 340),
                $this->regionHash($current, 400, 1300, 2400, 340),
                'Date, signature and QR must retain their approved alignment.'
            );
        } finally {
            imagedestroy($old);
            imagedestroy($current);
        }
    }

    public function test_long_arabic_names_and_courses_remain_inside_the_statement_margins(): void
    {
        $certificate = $this->certificate([
            'holder_name' => 'عبدالرحمن محمد عبدالعزيز إبراهيم',
            'course_name' => 'تصميم الإعلانات التجارية لمنصات التواصل الاجتماعي',
            'certificate_completion_text' => 'واجتاز مشروعاته',
        ]);
        $image = $this->decode(app(CertificateArtworkRenderer::class)->render($certificate, $this->destination()));
        try {
            self::assertGreaterThan(1000, $this->inkPixels($image, 224, 444, 2352, 660));
            self::assertSame(0, $this->inkPixels($image, 0, 420, 212, 720), 'The learner must not overflow the left margin.');
            self::assertSame(0, $this->colorPixels($image, 2588, 420, 212, 720, 0x101c2d), 'The learner must not overflow the right margin.');
            self::assertSame(0, $this->colorPixels($image, 2588, 420, 212, 720, 0x2c69db), 'The course must not overflow the right margin.');
            self::assertSame(0, $this->inkPixels($image, 224, 1150, 2352, 100), 'The statement must stay above the footer.');
        } finally {
            imagedestroy($image);
        }
    }

    public function test_project_claim_is_drawn_only_from_the_frozen_completion_text(): void
    {
        $renderer = app(CertificateArtworkRenderer::class);
        $theory = $renderer->render($this->certificate(), $this->destination());
        $emptyProjects = $renderer->render($this->certificate([
            'certificate_text_template_key' => 'projects',
            'certificate_completion_text' => '',
        ]), $this->destination());
        self::assertSame($theory, $emptyProjects, 'A template key alone cannot invent passed projects.');

        $practical = $renderer->render($this->certificate([
            'certificate_completion_text' => 'واجتاز مشروعاته',
        ]), $this->destination());
        $theoryImage = $this->decode($theory);
        $practicalImage = $this->decode($practical);
        try {
            self::assertSame(0, $this->inkPixels($theoryImage, 700, 1000, 1400, 80));
            self::assertGreaterThan(50, $this->inkPixels($practicalImage, 700, 1000, 1400, 80), 'The practical completion line must actually appear.');
            self::assertSame(
                $this->regionHash($theoryImage, 0, 1250, 2800, 650),
                $this->regionHash($practicalImage, 0, 1250, 2800, 650),
                'Adding a project claim must not shift the aligned footer.'
            );
        } finally {
            imagedestroy($theoryImage);
            imagedestroy($practicalImage);
        }
    }

    public function test_the_issued_date_changes_only_the_date_artwork_and_rendering_is_deterministic(): void
    {
        $renderer = app(CertificateArtworkRenderer::class);
        $certificate = $this->certificate();
        $first = $renderer->render($certificate, $this->destination());
        self::assertSame($first, $renderer->render($certificate, $this->destination()));
        $next = $renderer->render($this->certificate(['generated_at' => '2026-10-16 12:00:00']), $this->destination());
        $firstImage = $this->decode($first);
        $nextImage = $this->decode($next);
        try {
            self::assertNotSame(
                $this->regionHash($firstImage, 1824, 1400, 720, 196),
                $this->regionHash($nextImage, 1824, 1400, 720, 196),
                'The date must come from the issued snapshot rather than a template image.'
            );
            self::assertSame(
                $this->regionHash($firstImage, 0, 0, 2800, 1400),
                $this->regionHash($nextImage, 0, 0, 2800, 1400)
            );
            self::assertSame(
                $this->regionHash($firstImage, 0, 1400, 1800, 500),
                $this->regionHash($nextImage, 0, 1400, 1800, 500)
            );
        } finally {
            imagedestroy($firstImage);
            imagedestroy($nextImage);
        }
    }

    public function test_date_is_prepared_in_visual_rtl_order_without_reversing_its_digits(): void
    {
        $renderer = app(CertificateArtworkRenderer::class);
        $method = (new \ReflectionClass($renderer))->getMethod('visualDate');
        $date = $method->invoke($renderer, new \DateTimeImmutable('2026-09-15 12:00:00'));

        self::assertStringStartsWith('٢٠٢٦ ', $date);
        self::assertStringEndsWith(' ١٥', $date);
        self::assertStringNotContainsString('٦٢٠٢', $date);
        self::assertStringNotContainsString('٥١', $date);
    }

    #[DataProvider('literalNumericEntities')]
    public function test_numeric_entities_remain_literal_in_the_rendered_snapshot(string $field, string $literal, string $decoded): void
    {
        $renderer = app(CertificateArtworkRenderer::class);
        $certificate = $this->certificate([$field => $literal]);
        $snapshot = $certificate->getAttributes();
        $literalArtwork = $renderer->render($certificate, $this->destination());
        $decodedArtwork = $renderer->render($this->certificate([$field => $decoded]), $this->destination());

        self::assertNotSame(
            $decodedArtwork,
            $literalArtwork,
            'GD must not interpret the learner-authored numeric entity as another character.'
        );
        self::assertSame($snapshot, $certificate->getAttributes());
        self::assertSame($literal, $certificate->{$field});
    }

    public static function literalNumericEntities(): array
    {
        return [
            'decimal name entity' => ['holder_name', '&#65;', 'A'],
            'hexadecimal name entity' => ['holder_name', '&#x41;', 'A'],
            'entity in a course title' => ['course_name', 'HTML &#169;', 'HTML ©'],
        ];
    }

    #[DataProvider('invalidSnapshots')]
    public function test_incomplete_or_unsupported_snapshots_are_rejected(array $attributes): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CertificateArtworkRenderer::class)->render($this->certificate($attributes), $this->destination());
    }

    public static function invalidSnapshots(): array
    {
        return [
            'unknown version' => [['certificate_design_version' => 'unapproved']],
            'missing learner' => [['holder_name' => '   ']],
            'missing course' => [['course_name' => '']],
            'missing statement' => [['certificate_text' => '']],
            'missing identifier' => [['public_id' => '']],
            'missing date' => [['generated_at' => null]],
        ];
    }

    #[DataProvider('invalidDestinations')]
    public function test_invalid_qr_destinations_are_rejected(array $destination): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CertificateArtworkRenderer::class)->render($this->certificate(), $destination);
    }

    public static function invalidDestinations(): array
    {
        return [
            'unsupported type' => [['type' => 'payment', 'url' => 'https://preview.invalid/certificate', 'title' => 'تحقق من الشهادة', 'hint' => 'امسح الرمز']],
            'invalid URL' => [['type' => 'certificate', 'url' => 'not a URL', 'title' => 'تحقق من الشهادة', 'hint' => 'امسح الرمز']],
        ];
    }

    private function certificate(array $attributes = []): Certificate
    {
        return (new Certificate())->forceFill(array_replace([
            'public_id' => '00000000-0000-4000-8000-000000000000',
            'holder_name' => 'أحمد حسن',
            'course_name' => 'مبادئ التسويق',
            'certificate_design_version' => CertificateArtworkRenderer::VERSION,
            'certificate_text_template_key' => 'knowledge',
            'certificate_text' => 'أتم كورس',
            'certificate_completion_text' => '',
            'generated_at' => '2026-09-15 12:00:00',
        ], $attributes));
    }

    private function destination(): array
    {
        return ['type' => 'certificate', 'url' => 'https://preview.invalid/certificate', 'title' => 'تحقق من الشهادة', 'hint' => 'معاينة فقط'];
    }

    private function decode(string $bytes): \GdImage
    {
        $image = imagecreatefromstring($bytes);
        self::assertInstanceOf(\GdImage::class, $image);
        return $image;
    }

    private function inkPixels(\GdImage $image, int $left, int $top, int $width, int $height): int
    {
        $count = 0;
        for ($y = $top; $y < $top + $height; $y += 2) {
            for ($x = $left; $x < $left + $width; $x += 2) {
                if ((imagecolorat($image, $x, $y) & 0xffffff) !== self::PAPER) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function colorPixels(\GdImage $image, int $left, int $top, int $width, int $height, int $color): int
    {
        $count = 0;
        for ($y = $top; $y < $top + $height; $y++) {
            for ($x = $left; $x < $left + $width; $x++) {
                if ((imagecolorat($image, $x, $y) & 0xffffff) === $color) $count++;
            }
        }
        return $count;
    }

    private function regionHash(\GdImage $image, int $left, int $top, int $width, int $height): string
    {
        $crop = imagecrop($image, ['x' => $left, 'y' => $top, 'width' => $width, 'height' => $height]);
        self::assertInstanceOf(\GdImage::class, $crop);
        ob_start();
        try {
            imagepng($crop);
            return hash('sha256', (string) ob_get_contents());
        } finally {
            ob_end_clean();
            imagedestroy($crop);
        }
    }
}
