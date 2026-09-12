<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Services\PublicPortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortfolioReportingTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $role = 'student'): User
    {
        return User::query()->forceCreate([
            'name' => 'Learner', 'email' => Str::uuid().'@rokn.test',
            'password' => 'not-used', 'role' => $role, 'active' => true,
            'portfolio_slug' => 'rokn-'.strtolower(Str::random(24)),
        ]);
    }

    public function test_public_report_reaches_support_once_without_exposing_the_reporter(): void
    {
        $owner = $this->owner();
        $this->approveFixture($owner);
        $url = route('portfolio.report', $owner->portfolio_slug);
        $payload = ['message' => 'هذا المحتوى منسوخ دون إذن', 'email' => 'reporter@rokn.test'];
        $this->post($url, $payload)->assertRedirect(route('portfolio.public', $owner->portfolio_slug));
        $this->post($url, $payload)->assertRedirect();
        self::assertSame(1, FeedbackReport::query()->count());
        $report = FeedbackReport::query()->firstOrFail();
        self::assertSame('public_portfolio_report', $report->screen_key);
        self::assertSame($owner->id, $report->context['portfolio_owner_id']);
        self::assertNull($report->user_id);
        $this->get(route('portfolio.public', $owner->portfolio_slug))
            ->assertOk()->assertSee('الإبلاغ عن محتوى')->assertDontSee('reporter@rokn.test');
    }

    public function test_only_admin_can_suspend_and_restore_sharing_without_deleting_work(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $owner = $this->owner();
        $this->approveFixture($owner);
        $this->post(route('portfolio.report', $owner->portfolio_slug), ['message' => 'محتوى غير مناسب للنشر'])->assertRedirect();
        $report = FeedbackReport::query()->firstOrFail();
        $url = route('admin.feedback.portfolio-sharing', $report);
        $this->actingAs($this->owner('moderator'), 'web')->post($url, ['suspend' => true])->assertForbidden();
        $this->flushSession();
        $this->actingAs($this->owner('admin'), 'web')->post($url, ['suspend' => true])
            ->assertRedirect()->assertSessionHas('success');
        self::assertNotNull($owner->fresh()->portfolio_sharing_suspended_at);
        self::assertNull(app(PublicPortfolioService::class)->find($owner->portfolio_slug));
        self::assertNull(app(PublicPortfolioService::class)->mediaForPortfolio($owner->portfolio_slug, (string) Str::uuid()));
        self::assertNotNull(User::find($owner->id));
        $this->post($url, ['suspend' => false])->assertRedirect();
        self::assertNotNull(app(PublicPortfolioService::class)->find($owner->portfolio_slug));
    }

    public function test_invalid_or_suspended_profile_cannot_receive_public_reports(): void
    {
        $this->post('/@missing/report', ['message' => 'محتوى غير مناسب'])->assertNotFound();
        $owner = $this->owner();
        $owner->forceFill(['portfolio_sharing_suspended_at' => now()])->save();
        $this->post(route('portfolio.report', $owner->portfolio_slug), ['message' => 'محتوى غير مناسب'])->assertNotFound();
        self::assertSame(0, FeedbackReport::query()->count());
    }

    private function approveFixture(User $owner): void
    {
        $owner = $owner->fresh();
        $snapshot = app(\App\Services\PortfolioModerationService::class)->snapshot($owner);
        $owner->forceFill(['portfolio_sharing_status' => 'approved', 'portfolio_approved_hash' => $snapshot['hash']])->save();
    }
}
