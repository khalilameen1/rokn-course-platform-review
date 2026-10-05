<?php

declare(strict_types=1);

namespace App\Support;

/** One admission estimate, shared by offer reads and the actual request owners. */
final class AiRequestTokenEstimate
{
    public static function courseChatOutputLimit(array $terms): int
    {
        $configured = (int) config('openrouter.max_tokens', 800);

        return max(80, min((int) (($terms['max_output_tokens'] ?? null) ?: $configured), $configured));
    }

    public static function projectFollowupOutputLimit(array $terms): int
    {
        return max(80, min((int) config('openrouter.max_tokens', 800), (int) ($terms['max_output_tokens'] ?? 320)));
    }

    /** A lower bound for offer/allowance reads, not admission of an arbitrary draft. */
    public static function minimumProjectFollowup(array $terms, string $systemPrompt): int
    {
        return self::forContents([$systemPrompt, '؟'], self::projectFollowupOutputLimit($terms));
    }

    /** @param list<string> $contents Text only; attachment input is counted separately. */
    public static function forContents(array $contents, int $outputLimit, int $attachmentTokens = 0): int
    {
        return $outputLimit + (int) ceil(array_sum(array_map('strlen', $contents)) / 4)
            + max(0, $attachmentTokens);
    }
}
