<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductOperationsMediaDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_playable_video_remains_ready_when_course_metadata_needs_attention(): void
    {
        $administrator = User::query()->forceCreate([
            'name' => 'Operations Admin',
            'email' => 'media-operations@example.test',
            'role' => 'admin',
            'active' => true,
        ]);
        $course = Course::query()->forceCreate([
            'tenant_id' => 1,
            'name_ar' => 'كورس تجريبي',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ]);
        $guid = '1fba9140-3f1b-4f20-976f-97d30bf83adb';
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'مقطع جاهز',
            'video_source_type' => 'bunny',
            'bunny_video_id' => $guid,
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_media_id' => strtoupper($guid),
            'status' => 'ready',
            'protocol' => 'hls',
            'duration_seconds' => 75,
            'available_qualities' => ['auto', '720p'],
            'integrity_status' => 'attention',
            'integrity_issues' => [[
                'code' => 'course_cover_missing',
                'severity' => 'attention',
                'scope' => 'course',
                'reference' => $course->id,
            ]],
            'last_probe_at' => now(),
            'last_reconciled_at' => now(),
        ]);
        $invalidGuid = 'f3b13732-f9b0-4e25-a5c6-2d387a24536c';
        $invalidLesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'مقطع بملف تشغيل تالف',
            'video_source_type' => 'bunny',
            'bunny_video_id' => $invalidGuid,
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $invalidLesson->id,
            'provider' => 'bunny',
            'provider_media_id' => $invalidGuid,
            'status' => 'ready',
            'protocol' => 'hls',
            'duration_seconds' => 75,
            'available_qualities' => ['auto', '720p'],
            'integrity_status' => 'attention',
            'integrity_issues' => [[
                'code' => 'manifest_invalid',
                'severity' => 'attention',
                'scope' => 'lesson',
                'reference' => $invalidLesson->id,
            ]],
            'last_probe_at' => now(),
            'last_reconciled_at' => now(),
        ]);

        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($administrator, 'web')
            ->withSession(['warning' => 'الفيديو يعمل لكن توجد تفاصيل تحتاج مراجعة'])
            ->get(route('admin.product-operations.index'))
            ->assertOk()
            ->assertSeeText('صالح للمشاهدة 1')
            ->assertSeeText('تنبيهات 2')
            ->assertSeeText('مقطع جاهز')
            ->assertSeeText('جاهز')
            ->assertSeeText('غلاف الكورس غير مكتمل')
            ->assertSeeText('مقطع بملف تشغيل تالف')
            ->assertSeeText('غير جاهز')
            ->assertSeeText('ملف تشغيل الفيديو غير صالح')
            ->assertSeeText('الفيديو يعمل لكن توجد تفاصيل تحتاج مراجعة');
    }

    public function test_stale_ready_state_is_not_counted_as_playable(): void
    {
        $administrator = User::query()->forceCreate([
            'name' => 'Operations Admin',
            'email' => 'stale-media-operations@example.test',
            'role' => 'admin',
            'active' => true,
        ]);
        $course = Course::query()->forceCreate([
            'tenant_id' => 1,
            'name_ar' => 'كورس تبديل الفيديو',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'مقطع استبدل',
            'video_source_type' => 'bunny',
            'bunny_video_id' => '674f3116-fd39-4b42-9e4d-e88587963ea0',
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id,
            'provider' => 'bunny',
            'provider_media_id' => 'previous-generation',
            'status' => 'ready',
            'protocol' => 'hls',
            'duration_seconds' => 75,
            'available_qualities' => ['auto', '720p'],
            'integrity_status' => 'healthy',
            'last_probe_at' => now(),
            'last_reconciled_at' => now(),
        ]);

        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($administrator, 'web')
            ->get(route('admin.product-operations.index'))
            ->assertOk()
            ->assertSeeText('صالح للمشاهدة 0')
            ->assertSeeText('مقطع استبدل')
            ->assertSeeText('غير جاهز')
            ->assertSeeText('تفاصيل التشغيل تحتاج مراجعة');
    }
}
