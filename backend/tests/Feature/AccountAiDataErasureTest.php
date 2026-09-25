<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiEntitlementUsage;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\User;
use App\Services\AccountAiDataErasureService;
use App\Services\AccountDeletionService;
use App\Services\PaidAiCallExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AccountAiDataErasureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    public function test_unstarted_work_releases_the_reservation_without_recording_provider_cost(): void
    {
        [$user, $usage, $event] = $this->reservation(['request_context' => ['question' => 'private question']]);
        app(AccountAiDataErasureService::class)->eraseWithinDeletion($user->id);
        self::assertSame('failed', $event->fresh()->status);
        self::assertNull($event->fresh()->metadata);
        self::assertSame('0.000000', $event->fresh()->cost_usd);
        $this->assertUnusedAndReleased($usage);
        Http::assertNothingSent();
    }

    public function test_started_work_retains_unknown_cost_but_does_not_spend_learner_allowance(): void
    {
        [$user, $usage, $event] = $this->reservation([
            'provider_call_state' => 'started', 'request_context' => ['question' => 'private question'],
        ]);
        app(AccountAiDataErasureService::class)->eraseWithinDeletion($user->id);
        self::assertSame('completed', $event->fresh()->status);
        self::assertSame('0.020000', $event->fresh()->cost_usd);
        self::assertFalse($event->fresh()->metadata['entitlement_delivered']);
        self::assertArrayNotHasKey('request_context', $event->fresh()->metadata);
        $this->assertUnusedAndReleased($usage);
        Http::assertNothingSent();
    }

    public function test_landed_answer_is_erased_while_exact_cost_survives_without_another_provider_call(): void
    {
        [$user, $usage, $event] = $this->reservation($this->landed());
        app(AccountAiDataErasureService::class)->eraseWithinDeletion($user->id);
        self::assertSame('completed', $event->fresh()->status);
        self::assertSame('0.012500', $event->fresh()->cost_usd);
        self::assertSame(80, $event->fresh()->total_tokens);
        self::assertFalse($event->fresh()->metadata['entitlement_delivered']);
        self::assertSame('provider', $event->fresh()->metadata['cost_usage_source']);
        foreach (['provider_success_landing', 'accepted_response', 'provider_file_annotations', 'request_context'] as $key) {
            self::assertArrayNotHasKey($key, $event->fresh()->metadata);
        }
        $this->assertUnusedAndReleased($usage);
        $before = $event->fresh()->getAttributes();
        app(AccountAiDataErasureService::class)->eraseWithinDeletion($user->id);
        self::assertSame($before, $event->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_completed_event_keeps_only_operational_metadata_including_false_and_zero(): void
    {
        [$user, , $event] = $this->reservation([
            'entitlement_delivered' => false,
            'provider_call_attempt' => 0,
            'request_context' => ['prompt_version' => 'v1', 'feedback_level' => 'deep', 'question' => 'private'],
            'accepted_response' => 'private answer', 'custom_personal_field' => 'private',
        ]);
        $event->forceFill(['status' => 'completed', 'cost_usd' => '0.012500'])->save();
        [, , $other] = $this->reservation(['accepted_response' => 'other learner answer']);
        $otherBefore = $other->fresh()->getAttributes();
        app(AccountAiDataErasureService::class)->eraseWithinDeletion($user->id);
        self::assertSame([
            'entitlement_delivered' => false, 'provider_call_attempt' => 0,
            'prompt_version' => 'v1', 'feedback_level' => 'deep',
        ], $event->fresh()->metadata);
        self::assertSame('0.012500', $event->fresh()->cost_usd);
        self::assertSame($otherBefore, $other->fresh()->getAttributes());
        $metadata = $event->fresh()->metadata;
        app(AccountAiDataErasureService::class)->eraseWithinDeletion($user->id);
        self::assertSame($metadata, $event->fresh()->metadata);
    }

    public function test_outer_failure_restores_reservations_costs_and_personal_metadata_together(): void
    {
        [$user, $usage, $event] = $this->reservation($this->landed());
        $eventBefore = $event->fresh()->getAttributes();
        $usageBefore = $usage->fresh()->getAttributes();
        try {
            DB::transaction(function () use ($user): void {
                app(AccountAiDataErasureService::class)->eraseWithinDeletion($user->id);
                throw new \RuntimeException('account erasure failed');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('account erasure failed', $error->getMessage());
        }
        self::assertSame($eventBefore, $event->fresh()->getAttributes());
        self::assertSame($usageBefore, $usage->fresh()->getAttributes());
        app(AccountAiDataErasureService::class)->eraseWithinDeletion($user->id);
        self::assertSame('0.012500', $event->fresh()->cost_usd);
        $this->assertUnusedAndReleased($usage);
    }

    public function test_full_account_deletion_uses_erasure_without_discarding_paid_work(): void
    {
        [$user, $usage, $event] = $this->reservation($this->landed());
        app(AccountDeletionService::class)->delete($user);
        $deleted = User::withTrashed()->findOrFail($user->id);
        self::assertNotNull($deleted->deleted_at);
        self::assertSame('حساب محذوف', $deleted->name);
        self::assertSame('completed', $event->fresh()->status);
        self::assertSame('0.012500', $event->fresh()->cost_usd);
        self::assertArrayNotHasKey('accepted_response', $event->fresh()->metadata);
        self::assertArrayNotHasKey('provider_success_landing', $event->fresh()->metadata);
        $this->assertUnusedAndReleased($usage);
        Http::assertNothingSent();
    }

    private function assertUnusedAndReleased(AiEntitlementUsage $usage): void
    {
        $usage->refresh();
        self::assertSame(0, $usage->reserved_requests);
        self::assertSame(0, $usage->reserved_tokens);
        self::assertSame('0.000000', $usage->reserved_cost_usd);
        self::assertSame(0, $usage->used_requests);
        self::assertSame('0.000000', $usage->used_cost_usd);
    }

    private function landed(): array
    {
        return ['provider_call_state' => PaidAiCallExecutionService::LANDED,
            'provider_success_landing' => ['message' => 'private answer',
                'file_annotations' => [['name' => 'private file']],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 30,
                    'total_tokens' => 80, 'cost' => '0.012500', 'cost_reported' => true]]];
    }

    /** @return array{User, AiEntitlementUsage, AiUsageEvent} */
    private function reservation(array $metadata): array
    {
        $user = User::query()->forceCreate(['name' => 'Learner', 'email' => Str::uuid().'@example.test',
            'password' => 'unused', 'role' => 'client', 'active' => true]);
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'Erasure fixture',
            'price' => 900, 'authoring_version' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false]);
        $enrollment = CourseEnrollment::query()->forceCreate(['tenant_id' => 1,
            'user_id' => $user->id, 'course_id' => $course->id, 'enrolled_at' => now(), 'is_active' => true]);
        $usage = AiEntitlementUsage::query()->create(['enrollment_id' => $enrollment->id,
            'feature' => 'course_chat', 'reserved_requests' => 1, 'reserved_tokens' => 100,
            'reserved_cost_usd' => '0.020000', 'used_requests' => 0, 'used_tokens' => 0, 'used_cost_usd' => 0]);
        $event = AiUsageEvent::query()->create(['request_id' => (string) Str::uuid(),
            'enrollment_id' => $enrollment->id, 'user_id' => $user->id, 'course_id' => $course->id,
            'feature' => 'course_chat', 'model' => 'test/model', 'status' => 'reserved',
            'reserved_tokens' => 100, 'reserved_cost_usd' => '0.020000',
            'reservation_expires_at' => now()->addHour(), 'metadata' => $metadata]);
        return [$user, $usage, $event];
    }
}
