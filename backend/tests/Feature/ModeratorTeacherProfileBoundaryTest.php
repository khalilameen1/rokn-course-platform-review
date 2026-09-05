<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModeratorTeacherProfileBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_can_create_a_public_teacher_profile_without_login_credentials(): void
    {
        $moderator = $this->dashboardUser('moderator', 'creator@example.test');
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->post(route('admin.teachers.store'), [
                'name_ar' => 'محاضر بلا حساب',
                'name_en' => 'Profile Only Instructor',
                'job_title' => 'محاضر تصميم',
                'bio_ar' => 'نبذة عامة تظهر للطالب',
                'active' => '1',
                'authoring_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.teachers.index'));

        $teacher = User::query()
            ->where('role', 'teacher')
            ->where('name_ar', 'محاضر بلا حساب')
            ->firstOrFail();

        self::assertNull($teacher->email);
        self::assertNull($teacher->phone);
        self::assertNull($teacher->password);
        self::assertTrue((bool) $teacher->active);
    }

    public function test_moderator_manages_public_teacher_profile_without_account_credentials(): void
    {
        $moderator = $this->dashboardUser('moderator', 'editor@example.test');
        $teacher = $this->teacher();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->get(route('admin.teachers.index'))
            ->assertOk()
            ->assertSee($teacher->name_ar)
            ->assertDontSee($teacher->email)
            ->assertDontSee($teacher->phone);

        $this->actingAs($moderator)
            ->get(route('admin.teachers.show', $teacher))
            ->assertOk()
            ->assertSee($teacher->name_ar)
            ->assertDontSee($teacher->email)
            ->assertDontSee($teacher->phone);

        $this->actingAs($moderator)
            ->get(route('admin.teachers.edit', $teacher))
            ->assertOk()
            ->assertDontSee('name="email"', false)
            ->assertDontSee('name="phone"', false)
            ->assertDontSee('name="password"', false);

        $this->actingAs($moderator)
            ->patch(route('admin.teachers.update', $teacher), [
                ...$this->publicProfilePayload($teacher),
                'email' => 'captured@example.test',
                'phone' => '01000000000',
                'password' => 'changed-password',
                'password_confirmation' => 'changed-password',
            ])
            ->assertSessionHasErrors(['email', 'phone', 'password']);

        $this->actingAs($moderator)
            ->patch(route('admin.teachers.update', $teacher), [
                ...$this->publicProfilePayload($teacher),
                'job_title' => 'محاضر تصميم',
            ])
            ->assertRedirect(route('admin.teachers.index'));

        $teacher->refresh();
        self::assertSame('محاضر تصميم', $teacher->job_title);
        self::assertSame('teacher@example.test', $teacher->email);
        self::assertSame('01012345678', $teacher->phone);
        self::assertTrue(Hash::check('original-password', (string) $teacher->password));
    }

    public function test_administrator_retains_teacher_account_controls(): void
    {
        $administrator = $this->dashboardUser('admin', 'owner@example.test');
        $teacher = $this->teacher();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($administrator)
            ->get(route('admin.teachers.edit', $teacher))
            ->assertOk()
            ->assertSee('name="email"', false)
            ->assertSee('name="phone"', false)
            ->assertSee('name="password"', false);

        $permissions = app(AdminPermissionMatrix::class);
        self::assertTrue($permissions->allowsCapability('admin', AdminPermissionMatrix::ACCOUNT_CREDENTIALS));
        self::assertFalse($permissions->allowsCapability('moderator', AdminPermissionMatrix::ACCOUNT_CREDENTIALS));
    }

    public function test_administrator_can_save_a_profile_without_credentials_and_blank_fields_preserve_an_existing_account(): void
    {
        $administrator = $this->dashboardUser('admin', 'owner@example.test');
        $this->withoutMiddleware(RequireAdminMfa::class);

        $createPage = $this->actingAs($administrator)
            ->get(route('admin.teachers.create'))
            ->assertOk();
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$createPage->getContent());
        $xpath = new \DOMXPath($document);
        self::assertSame(0, $xpath->query('//input[@name="email" and @required]')->length);
        self::assertSame(0, $xpath->query('//input[@name="phone" and @required]')->length);
        self::assertSame(0, $xpath->query('//input[@name="password" and @required]')->length);
        self::assertSame(1, $xpath->query('//input[@name="email" and @disabled]')->length);
        self::assertSame(1, $xpath->query('//input[@name="phone" and @disabled]')->length);
        self::assertSame(1, $xpath->query('//input[@name="password" and @disabled]')->length);
        self::assertSame(0, $xpath->query('//input[@name="manage_credentials" and @checked]')->length);

        $this->actingAs($administrator)
            ->post(route('admin.teachers.store'), [
                'name_ar' => 'محاضر بملف فقط',
                'job_title' => 'محاضر تصميم',
                'bio_ar' => 'نبذة تظهر للطالب',
                'active' => '1',
                'authoring_request_id' => (string) Str::uuid(),
                // Password managers may populate fields at submit time. No
                // credential mutation is intentional without the opt-in.
                'email' => 'owner@example.test',
                'phone' => '01099999999',
                'password' => 'autofilled-password',
                'password_confirmation' => 'different-autofill',
            ])
            ->assertRedirect(route('admin.teachers.index'));

        $profile = User::query()
            ->where('role', 'teacher')
            ->where('name_ar', 'محاضر بملف فقط')
            ->firstOrFail();
        self::assertNull($profile->email);
        self::assertNull($profile->phone);
        self::assertNull($profile->password);

        $teacher = $this->teacher();
        $this->actingAs($administrator)
            ->patch(route('admin.teachers.update', $teacher), [
                ...$this->publicProfilePayload($teacher),
                'job_title' => 'محاضر أول',
                'email' => 'owner@example.test',
                'phone' => '01099999999',
                'password' => 'autofilled-password',
                'password_confirmation' => 'different-autofill',
            ])
            ->assertRedirect(route('admin.teachers.index'));

        $teacher->refresh();
        self::assertSame('محاضر أول', $teacher->job_title);
        self::assertSame('teacher@example.test', $teacher->email);
        self::assertSame('01012345678', $teacher->phone);
        self::assertTrue(Hash::check('original-password', (string) $teacher->password));

        $this->actingAs($administrator)
            ->post(route('admin.teachers.store'), [
                'name_ar' => 'محاضر له حساب',
                'active' => '1',
                'manage_credentials' => '1',
                'email' => 'new-teacher@example.test',
                'phone' => '01088888888',
                'password' => 'intentional-password',
                'password_confirmation' => 'intentional-password',
                'authoring_request_id' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('admin.teachers.index'));

        $account = User::query()->where('email', 'new-teacher@example.test')->firstOrFail();
        self::assertSame('01088888888', $account->phone);
        self::assertTrue(Hash::check('intentional-password', (string) $account->password));
    }

    public function test_profile_image_precedence_preserves_learner_avatar_and_empty_profiles_stay_empty(): void
    {
        Storage::fake('public');
        $student = new User();
        $student->forceFill([
            'name_ar' => 'طالب بصورة حديثة',
            'email' => 'student-avatar@example.test',
            'password' => Hash::make('student-password'),
            'role' => 'client',
            'profile_image' => 'users/current-student-avatar.jpg',
            'active' => true,
        ])->save();
        $student->allPhotos()->create([
            'path' => 'users/legacy-featured-avatar.jpg',
            'type' => 'featured',
        ]);

        self::assertSame(
            Storage::disk('public')->url('users/current-student-avatar.jpg'),
            $student->fresh()->profile_image_url
        );

        $legacyTeacher = new User();
        $legacyTeacher->forceFill([
            'name_ar' => 'محاضر بصورة قديمة',
            'email' => 'teacher-with-legacy-avatar@example.test',
            'password' => Hash::make('teacher-password'),
            'role' => 'teacher',
            'profile_image' => 'users/legacy-teacher-avatar.jpg',
            'active' => true,
        ])->save();
        self::assertSame(
            Storage::disk('public')->url('users/legacy-teacher-avatar.jpg'),
            $legacyTeacher->fresh()->profile_image_url
        );

        $emptyStudent = new User();
        $emptyStudent->forceFill([
            'name_ar' => 'طالب بلا صورة',
            'email' => 'student-without-avatar@example.test',
            'password' => Hash::make('student-password'),
            'role' => 'client',
            'active' => true,
        ])->save();
        self::assertNull($emptyStudent->fresh()->profile_image_url);

        $emptyTeacher = new User();
        $emptyTeacher->forceFill([
            'name_ar' => 'محاضر بلا صورة',
            'email' => 'teacher-without-avatar@example.test',
            'password' => Hash::make('teacher-password'),
            'role' => 'teacher',
            'active' => true,
        ])->save();
        self::assertNull($emptyTeacher->fresh()->profile_image_url);
    }

    public function test_teacher_course_count_and_list_exclude_staged_authoring_copies(): void
    {
        $administrator = $this->dashboardUser('admin', 'course-count-owner@example.test');
        $teacher = $this->teacher();
        $canonical = $this->course('الكورس المنشور', false);
        $draft = $this->course('نسخة التحرير الداخلية', true);
        $canonical->teachers()->attach($teacher);
        $draft->teachers()->attach($teacher);
        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id,
            'revision_course_id' => $draft->id,
            'base_authoring_version' => 1,
            'status' => CourseAuthoringRevision::DRAFT,
            'active_slot' => 'course-draft:'.$canonical->id,
            'clone_key' => (string) Str::uuid(),
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);

        $index = $this->actingAs($administrator)
            ->get(route('admin.teachers.index'))
            ->assertOk();
        $listedTeacher = $index->original->getData()['teachers']->firstWhere('id', $teacher->id);
        self::assertNotNull($listedTeacher);
        self::assertSame(1, (int) $listedTeacher->teaching_courses_count);

        $show = $this->actingAs($administrator)
            ->get(route('admin.teachers.show', $teacher))
            ->assertOk();
        $courses = $show->original->getData()['courses'];
        self::assertSame([$canonical->id], $courses->getCollection()->modelKeys());
        $show->assertSee('الكورس المنشور')->assertDontSee('نسخة التحرير الداخلية');
    }

    /** @return array<string, mixed> */
    private function publicProfilePayload(User $teacher): array
    {
        return [
            'name_ar' => $teacher->name_ar,
            'name_en' => $teacher->name_en,
            'job_title' => $teacher->job_title,
            'bio_ar' => $teacher->bio_ar,
            'bio_en' => $teacher->bio_en,
            'active' => '1',
            'editor_version' => hash('sha256', json_encode([
                $teacher->name_ar,
                $teacher->name_en,
                $teacher->email,
                $teacher->phone,
                $teacher->job_title,
                $teacher->bio_ar,
                $teacher->bio_en,
                (bool) $teacher->active,
                $teacher->photo?->path,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    private function teacher(): User
    {
        $teacher = new User();
        $teacher->forceFill([
            'name_ar' => 'محاضر ركن',
            'name_en' => 'Rokn Instructor',
            'email' => 'teacher@example.test',
            'phone' => '01012345678',
            'password' => Hash::make('original-password'),
            'job_title' => 'محاضر',
            'bio_ar' => 'نبذة عربية',
            'bio_en' => 'English bio',
            'role' => 'teacher',
            'active' => true,
        ])->save();

        return $teacher;
    }

    private function course(string $name, bool $draft): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'price' => 500,
            'is_coming_soon' => $draft,
            'is_catalog_visible' => !$draft,
            'authoring_version' => 1,
            'last_published_authoring_version' => $draft ? null : 1,
            'published_at' => $draft ? null : now(),
        ])->save();

        return $course;
    }

    private function dashboardUser(string $role, string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => $role === 'admin' ? 'مالك ركن' : 'محرر المحتوى',
            'email' => $email,
            'password' => Hash::make('dashboard-password'),
            'role' => $role,
            'active' => true,
        ])->save();

        return $user;
    }

}
