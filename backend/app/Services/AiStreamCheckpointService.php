<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseChatTurn;
use App\Models\ProjectFeedbackMessage;

/**
 * Persist non-authoritative AI progress without changing entitlement state.
 *
 * OpenRouterService throttles calls into this service. Terminal delivery is
 * still owned by each feature's existing settlement transaction.
 */
final class AiStreamCheckpointService
{
    public function courseChat(CourseChatTurn $turn, string $content): bool
    {
        $content = $this->content($content, 12000);
        if ($content === '') return false;

        try {
            return CourseChatTurn::query()
                ->whereKey($turn->id)
                ->where('status', CourseChatTurn::STREAMING)
                ->update(['answer' => $content, 'updated_at' => now()]) === 1;
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function projectMessage(ProjectFeedbackMessage $message, string $content): bool
    {
        $content = $this->content($content, 12000);
        if ($content === '') return false;

        try {
            return ProjectFeedbackMessage::query()
                ->whereKey($message->id)
                ->where('role', 'assistant')
                ->where('status', ProjectFeedbackMessage::STREAMING)
                ->update(['body' => $content, 'updated_at' => now()]) === 1;
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function content(string $content, int $limit): string
    {
        $content = (string) preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',
            '',
            $content
        );

        return mb_substr($content, 0, $limit);
    }
}
