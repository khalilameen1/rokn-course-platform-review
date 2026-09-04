<?php

declare(strict_types=1);

namespace Tests\Feature\API;

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
