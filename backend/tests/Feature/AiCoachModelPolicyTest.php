<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\OpenRouterRequestPolicy;
use App\Services\OpenRouterService;
use App\Support\CoachReplyText;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AiCoachModelPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'openrouter.api_key' => 'local-test-only',
            'openrouter.endpoint' => 'https://openrouter.test/chat/completions',
            'openrouter.default_model' => 'anthropic/claude-sonnet-5.5',
            'openrouter.project_model' => 'anthropic/claude-sonnet-5.5',
            'openrouter.project_gate_model' => 'anthropic/claude-haiku-5.5',
            'openrouter.allowed_models' => ['anthropic/claude-sonnet-5.5', 'anthropic/claude-haiku-5.5'],
            'openrouter.fallback_models' => [],
            'openrouter.reasoning_effort' => 'none',
            'openrouter.project_report_reasoning_headroom_tokens' => 2048,
            'openrouter.max_tokens' => 800,
        ]);
        Http::preventStrayRequests();
    }

    public function test_chat_and_project_discussion_use_the_strong_model_and_the_gate_uses_the_light_one(): void
    {
        $policy = app(OpenRouterRequestPolicy::class);

        self::assertSame('anthropic/claude-sonnet-5.5', $policy->configuredModel());
        self::assertSame('anthropic/claude-sonnet-5.5', $policy->configuredModel('project_model'));
        self::assertSame('anthropic/claude-haiku-5.5', $policy->configuredModel('project_gate_model'));
    }

    public static function claudeFiveModels(): array
    {
        return [
            ['anthropic/claude-sonnet-5.5', ['effort' => 'low', 'exclude' => true]],
            ['anthropic/claude-haiku-5.5', ['enabled' => false, 'exclude' => true]],
        ];
    }

    #[DataProvider('claudeFiveModels')]
    public function test_claude_five_models_get_their_advertised_parameters(string $model, array $reasoning): void
    {
        $this->fakeAnswer('تمام');

        app(OpenRouterService::class)->chat($model, [['role' => 'user', 'content' => 'سؤال']], .3, 600);

        Http::assertSent(function ($request) use ($model, $reasoning): bool {
            $payload = $request->data();
            self::assertSame($model, $payload['model']);
            self::assertArrayNotHasKey('temperature', $payload);
            self::assertSame($reasoning, $payload['reasoning']);
            self::assertSame(600, $payload['max_tokens']);

            return true;
        });
    }

    public function test_project_report_thinks_at_medium_with_reserved_headroom(): void
    {
        $this->fakeAnswer('تمام');

        app(OpenRouterService::class)->chat(
            'anthropic/claude-sonnet-5.5',
            [['role' => 'user', 'content' => 'راجع المشروع']],
            .35,
            600,
            reasoningEffort: 'medium'
        );

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            self::assertSame(['effort' => 'medium', 'exclude' => true], $payload['reasoning']);
            self::assertSame(600 + 2048, $payload['max_tokens']);

            return true;
        });
        self::assertSame(2048, OpenRouterRequestPolicy::reasoningHeadroom('medium'));
        self::assertSame(0, OpenRouterRequestPolicy::reasoningHeadroom('none'));
        self::assertSame(0, OpenRouterRequestPolicy::reasoningHeadroom(null));
    }

    public function test_formatted_reply_is_what_lands_and_returns(): void
    {
        $this->fakeAnswer("سؤال حلو!\nالكروب هو قص الصورة، وبيتعمل من أداة القص.\nتحب أساعدك في حاجة تانية؟");
        $landed = null;

        $result = app(OpenRouterService::class)->chat(
            'anthropic/claude-sonnet-5.5',
            [['role' => 'user', 'content' => 'يعني ايه كروب']],
            .35,
            600,
            'request-1',
            function (array $providerResult) use (&$landed): void {
                $landed = $providerResult['message'];
            },
            formatReply: CoachReplyText::format(...)
        );

        self::assertSame('الكروب هو قص الصورة وبيتعمل من أداة القص', $result['message']);
        self::assertSame($result['message'], $landed);
    }

    public function test_streamed_progress_is_formatted_like_the_final_reply(): void
    {
        $first = 'الكروب هو قص الصورة، '.rtrim(str_repeat('وبيتعمل من أداة القص ', 4));
        $second = '. خليه آخر خطوة.';
        Http::fake(['openrouter.test/*' => Http::response(
            $this->event(['id' => 'gen-1', 'choices' => [['delta' => ['content' => $first], 'finish_reason' => null]]])
            .$this->event(['id' => 'gen-1', 'choices' => [['delta' => ['content' => $second], 'finish_reason' => 'stop']]])
            .$this->event(['usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30, 'cost' => 0.001]])
            ."data: [DONE]\n\n",
            200,
            ['Content-Type' => 'text/event-stream']
        )]);
        $partials = [];

        $result = app(OpenRouterService::class)->chat(
            'anthropic/claude-sonnet-5.5',
            [['role' => 'user', 'content' => 'يعني ايه كروب']],
            .35,
            600,
            'request-2',
            null,
            function (string $partial) use (&$partials): void {
                $partials[] = $partial;
            },
            formatReply: CoachReplyText::format(...)
        );

        self::assertNotSame([], $partials);
        foreach ($partials as $partial) {
            self::assertStringNotContainsString('،', $partial);
        }
        self::assertSame($result['message'], $partials[array_key_last($partials)]);
        self::assertStringEndsWith("\nخليه آخر خطوة", $result['message']);
    }

    private function fakeAnswer(string $content): void
    {
        Http::fake(['openrouter.test/*' => Http::response([
            'id' => 'coach-test',
            'model' => 'anthropic/claude-sonnet-5.5',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20, 'total_tokens' => 30, 'cost' => .001],
        ])]);
    }

    /** @param array<string, mixed> $payload */
    private function event(array $payload): string
    {
        return 'data: '.json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        )."\n\n";
    }
}
