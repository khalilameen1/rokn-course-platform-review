<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\LevelResource;
use App\Filament\Resources\LevelResource\Pages\CreateLevel;
use App\Filament\Resources\LevelResource\Pages\EditLevel;
use App\Filament\Resources\LevelResource\Pages\ListLevels;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\Level;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentLevelWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('public');
        $user = new User;
        $user->forceFill(['role' => 'admin', 'active' => true])->save();
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_badge_upload_and_replacement_use_the_existing_media_owner(): void
    {
        Livewire::test(CreateLevel::class)->fillForm([
            'name_ar' => 'جونيور', 'name_en' => 'Junior', 'order' => 1,
            'badge_upload' => UploadedFile::fake()->image('junior.png'),
        ])->call('create')->assertHasNoFormErrors();
        $level = Level::query()->sole();
        $firstPath = $level->photo->path;
        Storage::disk('public')->assertExists($firstPath);
        Livewire::test(EditLevel::class, ['record' => $level->id])->fillForm([
            'name_ar' => 'المعتمد', 'badge_upload' => UploadedFile::fake()->image('replacement.png'),
        ])->call('save')->assertHasNoFormErrors();
        $level->refresh()->load('photo');
        self::assertSame('المعتمد', $level->name_ar);
        self::assertNotSame($firstPath, $level->photo->path);
        self::assertFalse($level->allPhotos()->where('path', $firstPath)->exists());
        Storage::disk('public')->assertExists($level->photo->path);
    }

    public function test_stale_badge_editor_cannot_overwrite_another_edit(): void
    {
        $level = $this->level('جونيور', 1);
        $editor = Livewire::test(EditLevel::class, ['record' => $level->id]);
        $level->update(['name_ar' => 'الأحدث']);
        $editor->fillForm(['name_ar' => 'قديم'])->call('save');
        self::assertSame('الأحدث', $level->fresh()->name_ar);
    }

    public function test_bulk_delete_preserves_both_course_and_earned_student_badge_references(): void
    {
        $courseLevel = $this->level('كورس', 1);
        $earnedLevel = $this->level('مكتسبة', 2);
        $unused = $this->level('فارغة', 3);
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100,
            'level_id' => $courseLevel->id, 'is_coming_soon' => true, 'is_catalog_visible' => false]);
        $learner = new User;
        $learner->forceFill(['role' => 'client', 'active' => true])->save();
        $earnedLevel->users()->attach($learner, ['course_id' => $course->id, 'earned_at' => now()]);
        Livewire::test(ListLevels::class)->callTableBulkAction('remove', [$courseLevel, $earnedLevel, $unused]);
        self::assertTrue(Level::query()->whereKey($courseLevel->id)->exists());
        self::assertTrue(Level::query()->whereKey($earnedLevel->id)->exists());
        self::assertFalse(Level::query()->whereKey($unused->id)->exists());
    }

    public function test_moderator_cannot_enter_the_platform_badge_editor(): void
    {
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(CreateLevel::class)->assertForbidden();
    }

    public function test_legacy_bookmarks_open_the_current_badge_editor(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $level = $this->level('جونيور', 1);
        $resource = LevelResource::class;
        $this->get(route('admin.levels.index'))->assertRedirect($resource::getUrl());
        $this->get(route('admin.levels.create'))->assertRedirect($resource::getUrl('create'));
        $this->get(route('admin.levels.edit', $level))->assertRedirect($resource::getUrl('edit', ['record' => $level->id]));
    }

    private function level(string $name, int $order): Level
    {
        return Level::query()->create(['name_ar' => $name, 'name_en' => 'Level', 'order' => $order]);
    }
}
