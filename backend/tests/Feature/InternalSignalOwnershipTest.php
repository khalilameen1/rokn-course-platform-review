<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InternalSignal;
use App\Models\RewardRule;
use App\Services\CourseAccessPlanService;
use App\Services\CurriculumCompletionService;
use App\Services\InternalSignalService;
use App\Services\LearningAchievementSignalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class InternalSignalOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_delivery_is_independent_of_learning_and_keeps_strict_replay_identity(): void
    {
        Queue::fake();
        foreach ([CurriculumCompletionService::class, CourseAccessPlanService::class,
            LearningAchievementSignalService::class] as $dependency) {
            $this->app->bind($dependency, static fn () => throw new \LogicException('Unexpected generic-signal dependency'));
        }
        $signals = app(InternalSignalService::class);
        $first = $signals->record('ai_usage.settled', 'event:9', ['event_id' => 9, 'facts' => ['b' => 2, 'a' => 1]]);
        $replay = $signals->record('ai_usage.settled', 'event:9', ['facts' => ['a' => 1, 'b' => 2], 'event_id' => 9]);
        self::assertSame($first->id, $replay->id);
        self::assertSame(1, InternalSignal::query()->count());
        $this->expectException(\UnexpectedValueException::class);
        $signals->record('ai_usage.settled', 'event:9', ['event_id' => 10]);
    }

    public function test_a_learning_achievement_captures_its_reward_once_and_never_adopts_a_later_rule(): void
    {
        Queue::fake();
        $rule = RewardRule::query()->updateOrCreate(['event_key' => 'first_project_passed'], [
            'title_ar' => 'First project', 'coins_amount' => 50, 'interval_count' => 1,
            'rolling_30_day_cap' => 50, 'is_active' => true,
        ]);
        Cache::forget('reward-rule:active:v2:first_project_passed');
        $signals = app(LearningAchievementSignalService::class);
        $payload = ['user_id' => 42, 'project_id' => 7, 'course_id' => 3];
        $first = $signals->record('project.passed.first_reward', 'user:42:project:7', $payload);
        self::assertSame(50, data_get($first->payload, 'reward_contract.coins_amount'));
        $rule->update(['coins_amount' => 200, 'rolling_30_day_cap' => 200]);
        Cache::forget('reward-rule:active:v2:first_project_passed');
        $replay = $signals->record('project.passed.first_reward', 'user:42:project:7', $payload);
        self::assertSame($first->id, $replay->id);
        self::assertSame($first->payload, $replay->payload);
        self::assertSame(1, InternalSignal::query()->count());
    }
}
