<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ContentWorkspace;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\TodayStats;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\User;
use App\Services\AdminHomeReadService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentContentWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_pagination_uses_the_owner_and_projects_only_display_fields(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'moderator', 'active' => true]), 'web');
        for ($index = 1; $index <= 13; $index++) {
            Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس '.$index, 'is_coming_soon' => true,
                'updated_at' => now()->subMinutes($index)]);
        }
        $page = Livewire::test(ContentWorkspace::class)->assertOk();
        $rows = $page->instance()->getTableRecords();
        self::assertSame(13, $rows->total());
        self::assertCount(12, $rows);
        self::assertSame('كورس 1', $rows->first()['title']);
        self::assertSame(['title', 'status', 'missing', 'modules', 'sections', 'updated', 'url', '__key'], array_keys($rows->first()));
        $page->call('gotoPage', 2);
        $rows = $page->instance()->getTableRecords();
        self::assertSame(2, $rows->currentPage());
        self::assertCount(1, $rows);
        self::assertSame('كورس 13', $rows->first()['title']);
        self::assertSame(13, $page->instance()->report()['contentSummary']['courses']);
        Http::assertNothingSent();
    }

    public function test_default_home_does_not_resolve_the_heavy_financial_report(): void
    {
        $this->app->bind(AdminHomeReadService::class, static function (): never {
            throw new \LogicException('Daily dashboard must not resolve the full financial report');
        });
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        $this->get(route('admin.dashboard'))->assertRedirect(Dashboard::getUrl());
        $this->get(Dashboard::getUrl())->assertOk();
        Livewire::test(TodayStats::class)->assertOk()->assertSee('مبيعات اليوم المؤكدة');
        Livewire::test(Dashboard::class)->assertActionHasUrl('content', ContentWorkspace::getUrl());
    }

    public function test_a_learner_cannot_open_the_content_workspace(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'client', 'active' => true]), 'web');
        $this->get(ContentWorkspace::getUrl())->assertForbidden();
    }
}
