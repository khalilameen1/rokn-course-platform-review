<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\PortfolioReviewResource;
use App\Filament\Resources\PortfolioReviewResource\Pages\ListPortfolioReviews;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\PortfolioItem;
use App\Models\User;
use App\Services\AdminPortfolioReviewReadService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentPortfolioReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->actingAs(User::query()->forceCreate(['name' => 'Queue admin', 'email' => 'queue-admin@example.test', 'role' => 'admin', 'active' => true]), 'web');
    }

    public function test_pending_queue_excludes_empty_private_hidden_and_deleting_work(): void
    {
        $selected = $this->owner('Selected owner');
        $this->work($selected);
        $empty = $this->owner('Empty owner');
        $private = $this->owner('Private owner');
        $this->work($private, ['is_public' => false]);
        $hidden = $this->owner('Hidden owner');
        $this->work($hidden, ['hidden_at' => now()]);
        $deleting = $this->owner('Deleting owner');
        $this->work($deleting, ['deletion_started_at' => now()]);
        $noMedia = $this->owner('No media owner');
        PortfolioItem::query()->create(['user_id' => $noMedia->id, 'title' => 'No media', 'is_public' => true]);

        Livewire::test(ListPortfolioReviews::class)->assertCanSeeTableRecords([$selected])
            ->assertCanNotSeeTableRecords([$empty, $private, $hidden, $deleting, $noMedia]);
    }

    public function test_filters_and_literal_name_search_do_not_grant_private_contact_search(): void
    {
        $match = $this->owner('Design_100%');
        $this->work($match);
        $other = $this->owner('DesignX1000');
        $this->work($other);
        $approved = $this->owner('Approved owner');
        $rejected = $this->owner('Rejected owner');
        $suspended = $this->owner('Suspended owner');
        // Decisions are fixture state, not new writes through a generic resource.
        $approved->forceFill(['portfolio_sharing_status' => 'approved'])->save();
        $rejected->forceFill(['portfolio_sharing_status' => 'rejected'])->save();
        $suspended->forceFill(['portfolio_sharing_suspended_at' => now()])->save();

        $page = Livewire::test(ListPortfolioReviews::class)->assertCanSeeTableRecords([$match, $other])
            ->assertCanNotSeeTableRecords([$approved, $rejected, $suspended]);
        $page->searchTable('_100%')->assertCanSeeTableRecords([$match])->assertCanNotSeeTableRecords([$other]);
        $page->searchTable($match->email)->assertCountTableRecords(0);
        $page->searchTable('')->filterTable('status', 'approved')->assertCanSeeTableRecords([$approved])->assertCountTableRecords(1);
        $page->filterTable('status', 'rejected')->assertCanSeeTableRecords([$rejected])->assertCountTableRecords(1);
        $page->filterTable('status', 'suspended')->assertCanSeeTableRecords([$suspended])->assertCountTableRecords(1);
        $this->get(route('admin.portfolio-reviews.index', ['status' => 'rejected']))
            ->assertRedirect(PortfolioReviewResource::listUrl(['status' => 'rejected']));
    }

    public function test_projection_counts_all_selected_work_without_per_row_queries_or_model_writes(): void
    {
        $owners = collect(range(1, 8))->map(function (int $index): User {
            $owner = $this->owner('Owner '.$index);
            $this->work($owner);
            $this->work($owner);
            $this->work($owner, ['is_public' => false]);

            return $owner;
        });
        DB::enableQueryLog();
        DB::flushQueryLog();
        $reader = app(AdminPortfolioReviewReadService::class);
        $rows = $reader->filterStatus($reader->query(), 'pending')->get();
        foreach ($rows as $row) {
            self::assertSame(2, $row->getAttribute('selected_projects_count'));
            self::assertNotEmpty($row->getFilamentName());
        }
        self::assertCount(8, $rows);
        self::assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        Livewire::test(ListPortfolioReviews::class)->assertCanSeeTableRecords($owners)
            ->assertTableActionHasUrl('preview', route('admin.portfolio-preview.show', $owners->first()), $owners->first());
        self::assertFalse(PortfolioReviewResource::canCreate());
        self::assertFalse(PortfolioReviewResource::canEdit($owners->first()));
        self::assertFalse(PortfolioReviewResource::canDelete($owners->first()));
    }

    private function owner(string $name): User
    {
        return User::query()->forceCreate(['name' => $name, 'email' => Str::uuid().'@portfolio-queue.test',
            'role' => 'client', 'active' => true, 'portfolio_slug' => 'queue-'.Str::lower(Str::random(20))]);
    }

    private function work(User $owner, array $overrides = []): void
    {
        $item = PortfolioItem::query()->create(['user_id' => $owner->id, 'title' => 'Selected project', 'is_public' => true, ...$overrides]);
        $item->mediaFiles()->create(['file_path' => 'portfolio/fixture.jpg', 'file_type' => 'image', 'content_sha256' => str_repeat('a', 64)]);
    }
}
