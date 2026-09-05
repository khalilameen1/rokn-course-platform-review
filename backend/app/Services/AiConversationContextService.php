<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiConversationContext;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectFeedbackThread;
use App\Models\User;
use App\Support\DatabaseCapabilities;
use App\Support\UnicodeText;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Durable extractive memory for long project-feedback threads without a
 * second model request.
 */
final class AiConversationContextService
{
    public function projectThread(
        ProjectFeedbackThread $thread,
        int $beforeMessageId,
        string $initialReportRequestId,
        int $characterBudget,
        string $focus
    ): string {
        return $this->rebuildAndSelect(
            (int) $thread->user_id,
            (int) $thread->course_id,
            'project_followup',
            (string) $thread->public_id,
            fn (): Collection => ProjectFeedbackMessage::query()
                ->where('thread_id', $thread->id)
                ->where('status', ProjectFeedbackMessage::COMPLETED)
                ->whereIn('role', ['user', 'assistant'])
                ->where('client_request_id', '<>', $initialReportRequestId)
                ->where('id', '<', $beforeMessageId)
                ->orderByDesc('id')
                ->limit(120)
                ->get(['id', 'role', 'body'])
                ->reverse()
                ->values(),
            static fn (ProjectFeedbackMessage $message): array => [
                'id' => (int) $message->id,
                'kind' => (string) $message->role,
                'text' => ($message->role === 'user' ? 'الطالب: ' : 'ركن: ')
                    . UnicodeText::limit(
                        UnicodeText::clean((string) $message->body),
                        520
                    ),
            ],
            $characterBudget,
            $focus,
            null
        );
    }

    private function rebuildAndSelect(
        int $userId,
        int $courseId,
        string $scope,
        string $scopeKey,
        callable $loadRows,
        callable $formatRow,
        int $characterBudget,
        string $focus,
        ?Carbon $expiresAt
    ): string {
        if (!DatabaseCapabilities::hasTable('ai_conversation_contexts')) return '';

        $entries = DB::transaction(function () use (
            $userId,
            $courseId,
            $scope,
            $scopeKey,
            $loadRows,
            $formatRow,
            $expiresAt
        ): array {
            if (!User::query()->whereKey($userId)->where('active', true)
                ->lockForUpdate()->exists()) return [];

            DB::table('ai_conversation_contexts')->insertOrIgnore([
                'user_id' => $userId,
                'course_id' => $courseId,
                'scope' => $scope,
                'scope_key' => $scopeKey,
                'covered_through_id' => 0,
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $checkpoint = AiConversationContext::query()
                ->where('user_id', $userId)
                ->where('course_id', $courseId)
                ->where('scope', $scope)
                ->where('scope_key', $scopeKey)
                ->lockForUpdate()
                ->firstOrFail();

            $rows = $loadRows();
            $structured = $rows->map(function ($row) use ($formatRow): array {
                $entry = $formatRow($row);
                return [
                    'id' => max(0, (int) ($entry['id'] ?? 0)),
                    'kind' => substr((string) ($entry['kind'] ?? 'fact'), 0, 24),
                    'text' => UnicodeText::limit(
                        UnicodeText::clean((string) ($entry['text'] ?? '')),
                        900
                    ),
                ];
            })->filter(
                static fn (array $entry): bool => $entry['id'] > 0 && $entry['text'] !== ''
            )->values()->all();

            $checkpoint->forceFill([
                'summary' => json_encode(
                    ['version' => 2, 'entries' => $structured],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'covered_through_id' => (int) ($rows->last()?->id ?? 0),
                'expires_at' => $expiresAt,
            ])->save();

            return $structured;
        }, 3);

        return $this->select($entries, $characterBudget, $focus);
    }

    /** @param list<array{id:int,kind:string,text:string}> $entries */
    private function select(array $entries, int $characterBudget, string $focus): string
    {
        if ($entries === []) return '';
        $budget = max(800, min(12000, $characterBudget));
        $tokens = collect(preg_split(
            '/[^\p{L}\p{N}]+/u',
            mb_strtolower(UnicodeText::clean($focus))
        ))->filter(static fn (string $token): bool => mb_strlen($token) >= 3)
            ->unique()->values()->all();

        $count = count($entries);
        $scores = [];
        foreach ($entries as $index => $entry) {
            $text = mb_strtolower($entry['text']);
            $relevance = 0;
            foreach ($tokens as $token) {
                if (mb_strpos($text, $token) !== false) $relevance++;
            }
            $scores[$entry['id']] = ($index < 2 ? 10000 : 0)
                + ($index >= max(0, $count - 6) ? 5000 + $index : 0)
                + ($relevance * 100);
        }

        usort($entries, static function (array $left, array $right) use ($scores): int {
            $score = $scores[$right['id']] <=> $scores[$left['id']];
            return $score !== 0 ? $score : ($right['id'] <=> $left['id']);
        });
        $selected = [];
        $used = 0;
        foreach ($entries as $entry) {
            $length = mb_strlen($entry['text']) + 1;
            if ($selected !== [] && $used + $length > $budget) continue;
            $selected[] = $entry;
            $used += $length;
        }
        usort($selected, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return implode("\n", array_column($selected, 'text'));
    }
}
