<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\TeacherResource;
use App\Filament\Resources\TeacherResource\Pages\CreateTeacher;
use App\Filament\Resources\TeacherResource\Pages\EditTeacher;
use App\Filament\Resources\TeacherResource\Pages\ListTeachers;
use App\Filament\Resources\TeacherResource\Pages\ViewTeacher;
use App\Filament\Resources\TeacherResource\RelationManagers\CoursesRelationManager;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentTeacherWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Durable upload bookkeeping cannot run inside RefreshDatabase's outer
        // transaction. Match the existing authoring-service test harness.
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Storage::fake('public');
        $this->operator('admin');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_instructor_creation_saves_public_profile_and_tracked_image(): void
    {
        Livewire::test(CreateTeacher::class)->fillForm([
            'name_ar' => 'مدرب رُكن', 'active' => true,
            'image' => UploadedFile::fake()->image('teacher.png'),
        ])->call('create')->assertHasNoFormErrors();
        $teacher = User::query()->where('role', 'teacher')->sole();
        self::assertNull($teacher->email);
        self::assertNull($teacher->password);
        self::assertTrue((bool) $teacher->active);
        Storage::disk('public')->assertExists($teacher->photo->path);
    }

    public function test_owner_can_explicitly_set_credentials_and_password_is_hashed(): void
    {
        Livewire::test(CreateTeacher::class)->fillForm([
            'name_ar' => 'مدرب', 'active' => true, 'manage_credentials' => true,
            'email' => 'instructor@example.test', 'password' => 'Instructor-pass-2026', 'password_confirmation' => 'Instructor-pass-2026',
        ])->call('create')->assertHasNoFormErrors();
        $teacher = User::query()->where('role', 'teacher')->sole();
        self::assertSame('instructor@example.test', $teacher->email);
        self::assertTrue(Hash::check('Instructor-pass-2026', $teacher->password));
    }

    public function test_moderator_cannot_read_or_forge_credentials_through_the_new_schema(): void
    {
        $teacher = $this->teacher();
        $this->operator('moderator');
        Livewire::test(EditTeacher::class, ['record' => $teacher->id])
            ->assertFormFieldDoesNotExist('email')
            ->assertFormFieldDoesNotExist('password')
            ->fillForm(['name_ar' => 'الاسم الجديد'])
            ->set('data.manage_credentials', true)->set('data.email', 'stolen@example.test')
            ->set('data.password', 'Stolen-pass-2026')->call('save')->assertHasNoFormErrors();
        self::assertSame('private@example.test', $teacher->fresh()->email);
        self::assertTrue(Hash::check('Original-pass-2026', $teacher->fresh()->password));
        self::assertSame('الاسم الجديد', $teacher->fresh()->name_ar);
        self::assertSame([], array_intersect_key(TeacherResource::profilePayload([
            'name_ar' => 'اسم', 'email' => 'stolen@example.test', 'manage_credentials' => true,
        ], false), ['email' => true, 'password' => true]));
    }

    public function test_stale_editor_is_rejected_and_fresh_editor_replaces_photo_without_replacing_credentials(): void
    {
        $teacher = $this->teacher();
        $editor = Livewire::test(EditTeacher::class, ['record' => $teacher->id]);
        $teacher->update(['job_title' => 'تعديل أحدث']);
        $editor->fillForm(['name_ar' => 'قديم'])->call('save');
        self::assertSame('مدرب', $teacher->fresh()->name_ar);
        Livewire::test(EditTeacher::class, ['record' => $teacher->id])->fillForm([
            'name_ar' => 'معتمد', 'image' => UploadedFile::fake()->image('new.png'),
        ])->call('save')->assertHasNoFormErrors();
        self::assertSame('معتمد', $teacher->fresh()->name_ar);
        self::assertSame('private@example.test', $teacher->fresh()->email);
        Storage::disk('public')->assertExists($teacher->fresh()->photo->path);
    }

    public function test_bulk_deletion_keeps_instructors_with_courses_and_deletes_unused_profiles(): void
    {
        $used = $this->teacher();
        $unused = new User;
        $unused->forceFill(['role' => 'teacher', 'name_ar' => 'بلا كورسات', 'active' => true])->save();
        Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100, 'teacher_id' => $used->id,
            'is_coming_soon' => true, 'is_catalog_visible' => false]);
        Livewire::test(ListTeachers::class)->callTableBulkAction('remove', [$used, $unused]);
        self::assertNotNull($used->fresh());
        $this->assertSoftDeleted('users', ['id' => $unused->id]);
    }

    public function test_existing_bookmarks_redirect_and_phone_keeps_its_ltr_presentation(): void
    {
        $teacher = $this->teacher();
        $teacher->update(['phone' => '201055550002']);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->get(route('admin.teachers.index'))->assertRedirect(TeacherResource::getUrl('index', ['search' => '']));
        $this->get(route('admin.teachers.create'))->assertRedirect(TeacherResource::getUrl('create'));
        $this->get(route('admin.teachers.edit', $teacher))->assertRedirect(TeacherResource::getUrl('edit', ['record' => $teacher->id]));
        $html = $this->get(TeacherResource::getUrl())->assertOk()->getContent();
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        self::assertGreaterThan(0, (new \DOMXPath($document))->query('//*[@dir="ltr" and normalize-space()="201055550002"]')->length);
        Livewire::test(EditTeacher::class, ['record' => $teacher->id])->fillForm(['manage_credentials' => true])
            ->assertFormFieldExists('phone', fn (TextInput $field): bool => $field->getExtraInputAttributes()['dir'] === 'ltr');
    }

    private function operator(string $role): void
    {
        $user = new User;
        $user->forceFill(['role' => $role, 'active' => true])->save();
        $this->actingAs($user, 'web');
    }

    public function test_native_profile_courses_are_paginated_searchable_and_scoped_to_the_selected_teacher(): void
    {
        $teacher = $this->teacher();
        $courses = [];
        foreach (range(1, 12) as $index) {
            $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس '.$index, 'is_coming_soon' => true]);
            $course->teachers()->attach($teacher);
            $courses[] = $course;
        }
        $other = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس آخر', 'is_coming_soon' => true]);
        $page = Livewire::test(CoursesRelationManager::class, ['ownerRecord' => $teacher, 'pageClass' => ViewTeacher::class]);
        $page->assertCountTableRecords(12)->assertCanSeeTableRecords([$courses[11]])->assertCanNotSeeTableRecords([$courses[0], $other]);
        $page->searchTable('كورس 1')->assertCanSeeTableRecords([$courses[0]])->assertCanNotSeeTableRecords([$courses[1], $other]);
        $this->operator('moderator');
        Livewire::test(CoursesRelationManager::class, ['ownerRecord' => $teacher, 'pageClass' => ViewTeacher::class])
            ->assertTableColumnHidden('active_enrollments_count');
        Livewire::test(ViewTeacher::class, ['record' => $teacher->id])->assertDontSee('private@example.test');
    }

    private function teacher(): User
    {
        $teacher = new User;
        $teacher->forceFill(['role' => 'teacher', 'name_ar' => 'مدرب', 'active' => true,
            'email' => 'private@example.test', 'password' => Hash::make('Original-pass-2026')])->save();

        return $teacher;
    }
}
