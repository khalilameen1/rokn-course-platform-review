<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ClassificationResource;
use App\Filament\Resources\ClassificationResource\Pages\CreateClassification;
use App\Filament\Resources\ClassificationResource\Pages\EditClassification;
use App\Filament\Resources\ClassificationResource\Pages\ListClassifications;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentClassificationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $user = new User;
        $user->forceFill(['role' => 'admin', 'active' => true])->save();
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_create_and_edit_preserve_hidden_course_membership(): void
    {
        $visible = $this->course(true);
        $hidden = $this->course(false);
        Livewire::test(CreateClassification::class)->fillForm([
            'name_ar' => 'صناعة المحتوى', 'name_en' => 'Content', 'home_order' => 3,
            'show_on_home' => true, 'course_ids' => [$visible->id],
        ])->call('create')->assertHasNoFormErrors();
        $row = Classification::query()->sole();
        $row->courses()->attach($hidden);
        Livewire::test(EditClassification::class, ['record' => $row->id])->fillForm([
            'name_ar' => 'المعتمد', 'course_ids' => [], 'show_on_home' => false,
        ])->call('save')->assertHasNoFormErrors();
        self::assertSame('المعتمد', $row->fresh()->name_ar);
        self::assertFalse((bool) $row->fresh()->show_on_home);
        self::assertSame([$hidden->id], $row->courses()->pluck('courses.id')->all());
    }

    public function test_hidden_course_cannot_be_forged_and_errors_belong_to_the_visible_form(): void
    {
        $hidden = $this->course(false);
        $editor = Livewire::test(CreateClassification::class)->fillForm([
            'name_ar' => 'اسم', 'name_en' => 'Row', 'home_order' => 1, 'course_ids' => [$hidden->id],
        ])->call('create');
        $editor->assertHasFormErrors(['course_ids.0']);
        self::assertSame(0, Classification::query()->count());
    }

    public function test_stale_edit_does_not_overwrite_and_bulk_delete_preserves_referenced_rows(): void
    {
        $used = Classification::query()->create(['name_ar' => 'مرتبط', 'name_en' => 'Used', 'home_order' => 1]);
        $unused = Classification::query()->create(['name_ar' => 'فارغ', 'name_en' => 'Unused', 'home_order' => 2]);
        $used->courses()->attach($this->course(true));
        $editor = Livewire::test(EditClassification::class, ['record' => $used->id]);
        $used->update(['name_ar' => 'الأحدث']);
        $editor->fillForm(['name_ar' => 'قديم'])->call('save');
        self::assertSame('الأحدث', $used->fresh()->name_ar);
        Livewire::test(ListClassifications::class)->callTableBulkAction('remove', [$used, $unused]);
        self::assertTrue(Classification::query()->whereKey($used->id)->exists());
        self::assertFalse(Classification::query()->whereKey($unused->id)->exists());
    }

    public function test_editorial_moderator_cannot_delete_taxonomy(): void
    {
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListClassifications::class)->assertTableBulkActionHidden('remove');
    }

    public function test_bookmarks_redirect_to_the_only_current_editor(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $row = Classification::query()->create(['name_ar' => 'صف', 'name_en' => 'Row']);
        $resource = ClassificationResource::class;
        $this->get(route('admin.classifications.index'))->assertRedirect($resource::getUrl());
        $this->get(route('admin.classifications.create'))->assertRedirect($resource::getUrl('create'));
        $this->get(route('admin.classifications.edit', $row))->assertRedirect($resource::getUrl('edit', ['record' => $row->id]));
    }

    private function course(bool $visible): Course
    {
        return Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100,
            'is_coming_soon' => true, 'is_catalog_visible' => $visible, 'authoring_version' => 1]);
    }
}
