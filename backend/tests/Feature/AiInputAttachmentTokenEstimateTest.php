<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AiInputAttachmentService;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AiInputAttachmentTokenEstimateTest extends TestCase
{
    public static function images(): array
    {
        return [
            // 12MP phone photo: scaled to 2576px on the long edge, then capped.
            'phone photo' => [4032, 3024, 4784],
            'portrait phone photo' => [3024, 4032, 4784],
            'screenshot within the long-edge limit' => [1170, 2532, 3950],
            'small image' => [1000, 800, 1067],
        ];
    }

    #[DataProvider('images')]
    public function test_image_estimate_follows_claude_vision_cost(int $width, int $height, int $expected): void
    {
        self::assertTrue(extension_loaded('gd'), 'Image fixtures require ext-gd.');
        $image = UploadedFile::fake()->image('submission.png', $width, $height);

        self::assertSame(
            $expected,
            app(AiInputAttachmentService::class)->estimatedUploadedFileTokens($image)
        );
    }

    public function test_two_phone_photos_fit_a_plus_report_budget(): void
    {
        $service = app(AiInputAttachmentService::class);
        $photo = UploadedFile::fake()->image('photo.jpg', 4032, 3024);
        $twoPhotos = 2 * $service->estimatedUploadedFileTokens($photo);

        // Plus project_feedback_token_budget is 20000, and the report keeps
        // 2048 tokens of reasoning headroom on top of the prompt itself.
        self::assertLessThan(20000 - 2048, $twoPhotos);
    }
}
