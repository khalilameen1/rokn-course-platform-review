<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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

        $this->actingAs($administrator)
            ->post(route('admin.teachers.store'), [
                'name_ar' => 'محاضر بملف فقط',
                'job_title' => 'محاضر تصميم',
                'bio_ar' => 'نبذة تظهر للطالب',
                'active' => '1',
                'authoring_request_id' => (string) Str::uuid(),
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
                'email' => '',
                'phone' => '',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('admin.teachers.index'));

        $teacher->refresh();
        self::assertSame('محاضر أول', $teacher->job_title);
        self::assertSame('teacher@example.test', $teacher->email);
        self::assertSame('01012345678', $teacher->phone);
        self::assertTrue(Hash::check('original-password', (string) $teacher->password));
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
