<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\FeedbackResource;
use App\Filament\Resources\FeedbackResource\Pages\ListFeedback;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\FeedbackReport;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentSupportInboxWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_filters_validate_before_the_applied_inbox_and_export_change(): void
    {
        $page = Livewire::test(ListFeedback::class)->set('tableDeferredFilters.criteria', ['from' => '2026-10-20', 'to' => '2026-10-19']);
        $page->call('applyTableFilters')->assertHasErrors(['tableDeferredFilters.criteria.to']);
        self::assertStringNotContainsString('2026-10-20', $page->instance()->exportUrl());
        $page->set('tableDeferredFilters.criteria', ['from' => null, 'to' => '2026-10-19', 'q' => 'بلاغ'])
            ->call('applyTableFilters')->assertHasNoErrors();
        parse_str((string) parse_url($page->instance()->exportUrl(), PHP_URL_QUERY), $query);
        self::assertSame('بلاغ', $query['q']);
        self::assertSame('2026-10-19', $query['to']);
    }

    public function test_saved_get_link_preserves_filters_and_list_cannot_write_case_records(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $filters = ['status' => 'reviewing', 'q' => 'نص محفوظ'];
        $redirect = $this->get(route('admin.feedback.index', $filters))->assertRedirect()->headers->get('Location');
        self::assertSame(parse_url(FeedbackResource::getUrl(), PHP_URL_PATH), parse_url($redirect, PHP_URL_PATH));
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        self::assertEquals($filters, $query['filters']['criteria']);
        $wanted = FeedbackReport::query()->create(['public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'reviewing',
            'priority' => 'normal', 'message' => 'نص محفوظ', 'version' => 1]);
        Livewire::withQueryParams(['filters' => ['criteria' => $filters]])->test(ListFeedback::class)->assertCanSeeTableRecords([$wanted]);
        self::assertFalse(FeedbackResource::canCreate());
        self::assertFalse(FeedbackResource::canEdit($wanted));
        self::assertFalse(FeedbackResource::canDelete($wanted));
        self::assertSame(1, $wanted->fresh()->version);
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListFeedback::class)->assertForbidden();
    }
}
