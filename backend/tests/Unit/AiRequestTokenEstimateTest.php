<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AiRequestTokenEstimate;
use Tests\TestCase;

final class AiRequestTokenEstimateTest extends TestCase
{
    public function test_estimate_includes_utf8_input_output_and_attachment_allowance(): void
    {
        // 4 ASCII bytes + 2 UTF-8 bytes = ceil(6 / 4), not character count.
        self::assertSame(822, AiRequestTokenEstimate::forContents(['abcd', '؟'], 800, 20));
    }

    public function test_extraction_preserves_existing_output_caps_and_fallbacks(): void
    {
        config(['openrouter.max_tokens' => 800]);
        self::assertSame(800, AiRequestTokenEstimate::courseChatOutputLimit([]));
        self::assertSame(800, AiRequestTokenEstimate::courseChatOutputLimit(['max_output_tokens' => 0]));
        self::assertSame(80, AiRequestTokenEstimate::courseChatOutputLimit(['max_output_tokens' => 40]));
        self::assertSame(800, AiRequestTokenEstimate::projectFollowupOutputLimit(['max_output_tokens' => 1200]));
        self::assertSame(320, AiRequestTokenEstimate::projectFollowupOutputLimit([]));
        self::assertSame(80, AiRequestTokenEstimate::projectFollowupOutputLimit(['max_output_tokens' => 0]));
    }
}
