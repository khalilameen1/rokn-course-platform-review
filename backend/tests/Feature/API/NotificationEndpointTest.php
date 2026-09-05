<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

/**
 * Feature tests covering Student Notification API endpoints:
 * unread count, recent notifications, all notifications, marking specific or all notifications read.
 */
class NotificationEndpointTest extends ApiTestCase
{
    public function test_can_view_all_notifications(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/notifications?filter=unread&per_page=20')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('pagination.per_page', 20);
    }

    public function test_course_and_lesson_notifications_load_their_course_without_an_invalid_course_relation(): void
    {
        $now = now();
        DB::table('student_notifications')->insert([
            [
                'id' => 2,
                'user_id' => $this->user->id,
                'notification_type' => 'course_update',
                'notifiable_type' => Course::class,
                'notifiable_id' => $this->courseId,
                'title_ar' => 'تحديث الكورس',
                'message_ar' => 'افتح الكورس',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'user_id' => $this->user->id,
                'notification_type' => 'new_course_lesson',
                'notifiable_type' => Lesson::class,
                'notifiable_id' => 10,
                'title_ar' => 'مقطع جديد',
                'message_ar' => 'شاهد الآن',
                'created_at' => $now->copy()->addSecond(),
                'updated_at' => $now->copy()->addSecond(),
            ],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/notifications?per_page=20')
            ->assertOk()
            ->assertJsonPath('data.0.id', 3)
            ->assertJsonPath('data.0.course_id', $this->courseId)
            ->assertJsonPath('data.0.link', 'rokn://course/'.$this->courseId)
            ->assertJsonPath('data.1.id', 2)
            ->assertJsonPath('data.1.course_id', $this->courseId)
            ->assertJsonPath('data.1.link', 'rokn://course/'.$this->courseId);

        self::assertCount(3, $response->json('data'));
    }

    public function test_can_mark_notification_as_read(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/notifications/1/mark-read')
            ->assertOk()
            ->assertJsonPath('data.id', 1)
            ->assertJsonPath('data.is_read', true);

        $this->assertDatabaseHas('student_notifications', [
            'id' => 1,
            'user_id' => $this->user->id,
            'is_read' => true,
        ]);

        $readAt = \App\Models\StudentNotification::query()->findOrFail(1)->read_at;
        $this->travel(5)->minutes();
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/notifications/1/mark-read')
            ->assertOk()
            ->assertJsonPath('data.read_at', $readAt?->toIso8601String());
    }

    public function test_can_mark_all_notifications_as_read(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/notifications/mark-all-read')
            ->assertOk()
            ->assertJsonPath('data.updated_count', 1);

        $this->assertDatabaseMissing('student_notifications', [
            'user_id' => $this->user->id,
            'is_read' => false,
        ]);
    }

    public function test_notification_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    public function test_parallel_notification_summary_routes_are_absent(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/notifications/unread-count')
            ->assertNotFound();
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/notifications/last-ten')
            ->assertNotFound();
    }
}
