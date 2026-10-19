<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\PathResource;
use App\Filament\Resources\PathResource\Pages\CreatePath;
use App\Filament\Resources\PathResource\Pages\EditPath;
use App\Filament\Resources\PathResource\Pages\ListPaths;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\Path;
use App\Models\User;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminPathAuthoringService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentPathWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = new User;
        $user->forceFill(['email' => 'paths@example.test', 'role' => 'admin', 'active' => true])->save();
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_create_uses_schema_and_owning_service_to_attach_courses_and_interests(): void
    {
        $interest = Classification::query()->create(['name_ar' => 'التصميم', 'name_en' => 'Design']);
        $course = $this->course();
        Livewire::test(CreatePath::class)->fillForm([
            'title_ar' => 'تصميم البراند', 'title_en' => 'Brand design',
            'interest_ids' => [$interest->id], 'course_ids' => [$course->id],
        ])->call('create')->assertHasNoFormErrors();
        $path = Path::query()->sole();
        self::assertSame($path->id, $course->fresh()->path_id);
        self::assertSame([$interest->id], $path->interests()->pluck('classifications.id')->all());
    }

    public function test_forged_selection_is_rejected_before_domain_writes(): void
    {
        Livewire::test(CreatePath::class)->fillForm([
            'title_ar' => 'مسار', 'title_en' => 'Path', 'interest_ids' => [999999],
        ])->call('create')->assertHasFormErrors();
        self::assertSame(0, Path::query()->count());
    }

    public function test_classic_bookmarks_lead_to_the_single_current_editor(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $path = Path::query()->create(['title_ar' => 'مسار', 'title_en' => 'Path']);
        $resource = PathResource::class;
        $this->get(route('admin.paths.index'))->assertRedirect($resource::getUrl('index', ['search' => '']));
        $this->get(route('admin.paths.create'))->assertRedirect($resource::getUrl('create'));
        $this->get(route('admin.paths.edit', $path))->assertRedirect($resource::getUrl('edit', ['record' => $path->id]));
    }

    public function test_edit_preserves_optimistic_concurrency_and_does_not_overwrite_other_editor(): void
    {
        $path = Path::query()->create(['title_ar' => 'الأصل', 'title_en' => 'Original']);
        $editor = Livewire::test(EditPath::class, ['record' => $path->id]);
        $path->update(['title_ar' => 'تعديل آخر']);
        $editor->fillForm(['title_ar' => 'تعديل متأخر'])->call('save');
        self::assertSame('تعديل آخر', $path->fresh()->title_ar);
        Livewire::test(EditPath::class, ['record' => $path->id])->fillForm(['title_ar' => 'المعتمد'])
            ->call('save')->assertHasNoFormErrors();
        self::assertSame('المعتمد', $path->fresh()->title_ar);
    }

    public function test_bulk_delete_uses_existing_reference_guard_instead_of_direct_model_deletion(): void
    {
        $used = Path::query()->create(['title_ar' => 'مرتبط', 'title_en' => 'Used']);
        $unused = Path::query()->create(['title_ar' => 'فارغ', 'title_en' => 'Unused']);
        $course = $this->course();
        $course->update(['path_id' => $used->id]);
        Livewire::test(ListPaths::class)->callTableBulkAction('remove', [$used, $unused]);
        self::assertTrue(Path::query()->whereKey($used->id)->exists());
        self::assertFalse(Path::query()->whereKey($unused->id)->exists());
        self::assertSame($used->id, $course->fresh()->path_id);
    }

    public function test_create_receipt_replays_one_resource_and_rejects_changed_input(): void
    {
        $intent = (string) Str::uuid();
        $data = ['title_ar' => 'مسار', 'title_en' => 'Path'];
        $ledger = app(AdminAuthoringCreateIntentService::class);
        request()->setUserResolver(fn () => auth('web')->user());
        $create = fn ($complete): Path => app(AdminPathAuthoringService::class)->create($data, $complete);
        $first = $ledger->createResource('filament.paths.store', $intent, Path::class, $data, $create);
        $second = $ledger->createResource('filament.paths.store', $intent, Path::class, $data, $create);
        self::assertSame($first->id, $second->id);
        self::assertSame(1, Path::query()->count());
        $this->expectException(ValidationException::class);
        $ledger->createResource('filament.paths.store', $intent, Path::class, ['title_ar' => 'تغير'], $create);
    }

    private function course(): Course
    {
        $course = new Course;
        $course->forceFill(['tenant_id' => 1, 'name_ar' => 'كورس التصميم', 'price' => 500, 'is_coming_soon' => true, 'is_catalog_visible' => false, 'authoring_version' => 1])->save();

        return $course;
    }
}
