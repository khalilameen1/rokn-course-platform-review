<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Services\BunnyService;
use App\Services\CourseAccessPlanService;
use App\Services\PortfolioUploadAccessService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;

final class PortfolioUploadAccessTest extends ApiTestCase
{
    public static function deniedSubscriptions(): array
    {
        return array_map(fn (string $case): array => [$case], [
            'none', 'watch_only', 'grant', 'expired', 'inactive', 'pending', 'refunded',
        ]);
    }

    #[DataProvider('deniedSubscriptions')]
    public function test_ineligible_subscriptions_cannot_allocate_storage(string $case): void
    {
        if ($case !== 'none') {
            $enrollment = $this->enroll($case !== 'watch_only');
            if ($case === 'expired') $enrollment->update(['expires_at' => now()->subSecond()]);
            if ($case === 'inactive') $enrollment->update(['is_active' => false]);
            if ($case === 'grant') {
                DB::table('course_codes')->where('id', 1)->update(['is_grant' => true]);
                DB::table('orders')->where('id', $enrollment->order_id)->update([
                    'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
                    'course_code_id' => 1,
                ]);
            }
            if ($case === 'pending' || $case === 'refunded') {
                DB::table('orders')->where('id', $enrollment->order_id)->update(
                    $case === 'pending' ? ['status' => 'pending'] : ['financial_status' => 'refunded']
                );
            }
        }
        // No provider method may run, including video allocation/authorization.
        $this->app->instance(BunnyService::class, Mockery::mock(BunnyService::class));
        $this->actingAs($this->user, 'api');
        $hasSubscription = in_array($case, ['watch_only', 'grant'], true);
        $expectedMessage = $hasSubscription
            ? PortfolioUploadAccessService::UPGRADE_MESSAGE
            : PortfolioUploadAccessService::SUBSCRIBE_MESSAGE;
        $this->getJson('/api/v1/portfolio/upload-access')->assertOk()
            ->assertJsonPath('data.can_upload', false)
            ->assertJsonPath('data.has_subscription', $hasSubscription)
            ->assertJsonPath('data.message', $expectedMessage);
        $this->postJson('/api/v1/portfolio', ['title' => 'عمل جديد'])
            ->assertForbidden()->assertJsonPath('code', PortfolioUploadAccessService::DENIED_CODE)
            ->assertJsonPath('has_subscription', $hasSubscription)
            ->assertJsonPath('message', $expectedMessage);
        $this->post('/api/v1/portfolio/1/media', [
            'file' => UploadedFile::fake()->image('work.jpg', 100, 100)->size(2),
            'file_type' => 'image', 'client_request_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertForbidden()
            ->assertJsonPath('code', PortfolioUploadAccessService::DENIED_CODE);
        $this->postJson('/api/v1/portfolio/1/media/video-uploads', [
            'idempotency_key' => (string) Str::uuid(), 'size' => 100,
            'mime' => 'video/mp4', 'original_name' => 'work.mp4', 'sha256' => str_repeat('a', 64),
        ])->assertForbidden()->assertJsonPath('code', PortfolioUploadAccessService::DENIED_CODE);
        $this->postJson('/api/v1/portfolio/1/media/video-uploads/renew', ['claim' => 'old-claim'])
            ->assertForbidden()->assertJsonPath('code', PortfolioUploadAccessService::DENIED_CODE);
        self::assertSame(0, DB::table('portfolio_media')->count());
        self::assertSame(0, DB::table('portfolio_video_uploads')->count());
        self::assertSame(1, DB::table('portfolio_items')->count());
    }

    public function test_certificate_subscription_unlocks_before_completion_without_projects_and_uses_its_snapshot(): void
    {
        $enrollment = $this->enroll(true);
        self::assertNull($enrollment->completed_at);
        DB::table('course_access_plans')->where('id', $enrollment->access_plan_id)
            ->update(['certificate_enabled' => false]);
        DB::table('courses')->where('id', $this->courseId)->update(['is_coming_soon' => true]);
        $this->actingAs($this->user, 'api');
        $this->getJson('/api/v1/portfolio/upload-access')->assertOk()->assertJsonPath('data.can_upload', true);
        $this->postJson('/api/v1/portfolio', ['title' => 'عمل مستقل'])->assertOk();
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('uploadFileToStorage')->once()->andReturn('portfolio/work.jpg');
        $bunny->shouldReceive('consumeStorageCleanupCandidate')->once()->with('portfolio/work.jpg');
        $bunny->shouldReceive('generateBunnySignedUrl')->andReturn('https://media.example.test/work.jpg');
        $this->app->instance(BunnyService::class, $bunny);
        $this->post('/api/v1/portfolio/1/media', [
            'file' => UploadedFile::fake()->image('work.jpg', 100, 100)->size(2),
            'file_type' => 'image', 'client_request_id' => (string) Str::uuid(),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_catalogue_upgrade_does_not_upgrade_an_existing_watch_only_subscription(): void
    {
        $enrollment = $this->enroll(false);
        DB::table('course_access_plans')->where('id', $enrollment->access_plan_id)
            ->update(['certificate_enabled' => true]);
        self::assertFalse(app(PortfolioUploadAccessService::class)->allows($this->user));
    }

    public function test_revoked_upload_rights_do_not_remove_existing_work_or_block_read_edit_and_delete(): void
    {
        $this->actingAs($this->user, 'api');
        $this->getJson('/api/v1/portfolio')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/portfolio/1')->assertOk();
        $this->postJson('/api/v1/portfolio/1', ['title' => 'اسم جديد'])->assertOk();
        $this->deleteJson('/api/v1/portfolio/1')->assertOk();
    }

    public function test_access_is_rechecked_after_expiry_and_is_not_inherited_from_another_account(): void
    {
        $enrollment = $this->enroll(true);
        $access = app(PortfolioUploadAccessService::class);
        self::assertTrue($access->allows($this->user));
        $enrollment->update(['expires_at' => now()->subSecond()]);
        self::assertFalse($access->allows($this->user));
        $enrollment->update(['expires_at' => null, 'user_id' => $this->user->id + 100]);
        self::assertFalse($access->allows($this->user));
    }

    private function enroll(bool $certificate): CourseEnrollment
    {
        $plan = CourseAccessPlan::query()->where('course_id', $this->courseId)->where('code', 'basic')->firstOrFail();
        $plan->update(['certificate_enabled' => $certificate]);
        // A certificate plan permits project features, but a theory course may
        // contain no projects. Never invent an invalid watch-only certificate plan.
        $plan->setAttribute('projects_enabled', $certificate);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $this->user->id, 'course_id' => $this->courseId,
            'status' => 'approved', 'financial_status' => 'settled', 'final_amount' => 100,
        ]);

        return CourseEnrollment::query()->forceCreate([
            'user_id' => $this->user->id, 'course_id' => $this->courseId,
            'order_id' => $orderId, 'is_active' => true,
            'access_plan_id' => $plan->id, 'access_plan_order_id' => $orderId,
            'access_plan_snapshot' => app(CourseAccessPlanService::class)->snapshot($plan),
        ]);
    }
}
