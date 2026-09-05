<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Http\Resources\BaseCourseResource;
use App\Models\Course;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TeacherPortraitUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The upload ledger must commit before bytes are written. This test
        // therefore owns a fresh in-memory database instead of running inside
        // RefreshDatabase's outer transaction.
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    public function test_uploaded_portrait_survives_create_and_replacement_across_dashboard_and_course_contracts(): void
    {
        Storage::fake('public');
        $moderator = $this->dashboardUser();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->post(route('admin.teachers.store'), [
                'name_ar' => 'محاضر بصورة',
                'name_en' => 'Portrait Instructor',
                'job_title' => 'محاضر رسوم',
                'bio_ar' => 'نبذة عامة',
                'active' => '1',
                'authoring_request_id' => (string) Str::uuid(),
                'image' => $this->uploadedPortrait('portrait-one.png'),
            ])
            ->assertRedirect(route('admin.teachers.index'));

        $teacher = User::query()
            ->where('role', 'teacher')
            ->where('name_ar', 'محاضر بصورة')
            ->with('photo')
            ->firstOrFail();
        $firstPath = (string) $teacher->photo?->path;
        $firstUrl = Storage::disk('public')->url($firstPath);
        self::assertNotSame('', $firstPath);
        Storage::disk('public')->assertExists($firstPath);
        self::assertSame($firstUrl, $teacher->profile_image_url);

        $this->actingAs($moderator)
            ->get(route('admin.teachers.index'))
            ->assertOk()
            ->assertSee($firstUrl, false);
        $this->actingAs($moderator)
            ->get(route('admin.teachers.show', $teacher))
            ->assertOk()
            ->assertSee($firstUrl, false);
        $this->actingAs($moderator)
            ->get(route('admin.teachers.edit', $teacher))
            ->assertOk()
            ->assertSee($firstUrl, false);

        $teacher->forceFill(['profile_image' => 'users/imported-profile.jpg'])->save();
        $teacher->refresh()->load('photo');
        self::assertSame($firstUrl, $teacher->profile_image_url);

        $course = Course::factory()->create([
            'tenant_id' => 1,
            'teacher_id' => $teacher->id,
            'is_coming_soon' => true,
            'is_catalog_visible' => true,
        ]);
        $course->teachers()->attach($teacher);
        $course->load(['teachers.photo', 'classifications', 'accessPlans', 'modules.sections.sectionable']);
        self::assertSame(
            $firstUrl,
            data_get((new BaseCourseResource($course))->resolve(), 'teachers.0.image')
        );

        $this->actingAs($moderator)
            ->patch(route('admin.teachers.update', $teacher), [
                ...$this->publicProfilePayload($teacher),
                'image' => $this->uploadedPortrait('portrait-two.png'),
            ])
            ->assertRedirect(route('admin.teachers.index'));

        $teacher->refresh()->load('photo');
        $secondPath = (string) $teacher->photo?->path;
        $secondUrl = Storage::disk('public')->url($secondPath);
        self::assertNotSame($firstPath, $secondPath);
        self::assertSame(1, $teacher->allPhotos()->where('type', 'featured')->count());
        self::assertFalse($teacher->allPhotos()->where('path', $firstPath)->exists());
        Storage::disk('public')->assertExists($secondPath);
        self::assertSame($secondUrl, $teacher->profile_image_url);

        $this->actingAs($moderator)
            ->get(route('admin.teachers.index'))
            ->assertOk()
            ->assertSee($secondUrl, false)
            ->assertDontSee($firstUrl, false);

        $course->refresh()->load(['teachers.photo', 'classifications', 'accessPlans', 'modules.sections.sectionable']);
        self::assertSame(
            $secondUrl,
            data_get((new BaseCourseResource($course))->resolve(), 'teachers.0.image')
        );
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

    private function dashboardUser(): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'portrait-editor@example.test',
            'password' => Hash::make('dashboard-password'),
            'role' => 'moderator',
            'active' => true,
        ])->save();

        return $user;
    }

    private function uploadedPortrait(string $name): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            true
        );
        $path = tempnam(sys_get_temp_dir(), 'rokn-teacher-');
        if (!is_string($bytes) || !is_string($path) || file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('Could not create the teacher portrait fixture.');
        }

        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
