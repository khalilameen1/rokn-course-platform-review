<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Feature tests covering Saved Folders / Bookmarks API endpoints:
 * listing folders, creating folders, viewing folder items, saving/removing lessons, and deleting folders.
 */
class SavedFolderEndpointTest extends ApiTestCase
{
    public function test_saved_lessons_requires_authentication(): void
    {
        $this->getJson('/api/v1/saved-lessons')->assertUnauthorized();
    }

    public function test_saved_lessons_is_distinct_paginated_and_only_exposes_owned_folder_memberships(): void
    {
        DB::table('saved_folders')->insert([
            ['id' => 2, 'user_id' => $this->user->id, 'name' => 'Watch next', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 3, 'user_id' => $this->user->id, 'name' => 'Review', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $otherUser = User::query()->create([
            'name' => 'Other User',
            'email' => 'other-saved@rokn.test',
            'phone' => '01000000001',
            'password' => bcrypt('password'),
            'active' => true,
        ]);
        $otherFolderId = DB::table('saved_folders')->insertGetId([
            'user_id' => $otherUser->id,
            'name' => 'Must stay private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('saved_folder_lessons')->where('lesson_id', 10)->delete();
        DB::table('saved_folder_lessons')->insert([
            ['saved_folder_id' => 2, 'lesson_id' => 10, 'created_at' => now()->subMinute(), 'updated_at' => now()->subMinute()],
            ['saved_folder_id' => 3, 'lesson_id' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['saved_folder_id' => $otherFolderId, 'lesson_id' => 10, 'created_at' => now()->addMinute(), 'updated_at' => now()->addMinute()],
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-lessons?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.per_page', 1)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.lessons')
            ->assertJsonPath('data.lessons.0.id', 10)
            ->assertJsonCount(2, 'data.lessons.0.folder_memberships');

        $memberships = collect($response->json('data.lessons.0.folder_memberships'));
        self::assertSame([2, 3], $memberships->pluck('id')->sort()->values()->all());
        self::assertFalse($memberships->contains('name', 'Must stay private'));
        self::assertNotEmpty($response->json('data.lessons.0.saved_at'));
    }

    public function test_saved_lessons_rejects_unbounded_page_sizes(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-lessons?per_page=51')
            ->assertUnprocessable();
    }

    public function test_saved_lesson_state_preserves_numeric_lesson_ids(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-lessons/state?lesson_ids[]=10')
            ->assertOk()
            ->assertJson([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل حالة الحفظ',
                'data' => ['saved_lesson_ids' => [10]],
            ]);
    }

    public function test_can_list_saved_folders(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-folders')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('data.0.image', asset('courses/test-course.jpg'))
            ->assertJsonPath('data.0.lessons_count', 1);
    }

    public function test_saved_folder_uses_the_first_saved_lesson_image(): void
    {
        DB::table('lessons')->where('id', 10)->update(['image' => 'lesson-cover.jpg']);
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-folders')
            ->assertOk()
            ->assertJsonPath('data.0.image', url('lesson-cover.jpg'))
            ->assertJsonPath('data.0.lessons_count', 1);
    }

    public function test_can_create_saved_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/saved-folders', [
            'name' => 'My Bookmark Folder'
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_visually_identical_unicode_folder_names_do_not_create_duplicates(): void
    {
        $this->actingAs($this->user, 'api')->postJson('/api/v1/saved-folders', [
            'name' => '  خطتي   القادمة  ',
        ])->assertCreated();

        $this->actingAs($this->user, 'api')->postJson('/api/v1/saved-folders', [
            'name' => "خ\u{200F}طتي القادمة",
        ])->assertOk()
            ->assertJsonPath('message', 'المجلد موجود بالفعل');

        self::assertSame(1, DB::table('saved_folders')
            ->where('user_id', $this->user->id)
            ->where('normalized_name', 'خطتي القادمة')
            ->count());
    }

    public function test_can_view_saved_folder_items(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-folders/1')
            ->assertOk()
            ->assertJsonPath('data.lessons.0.id', 10)
            ->assertJsonPath('data.lessons.0.duration_minutes', 15);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/saved-folders/1/lessons')
            ->assertOk()
            ->assertJsonPath('data.lessons.0.id', 10)
            ->assertJsonPath('data.lessons.0.duration_minutes', 15);
    }

    public function test_can_save_lesson_to_folder(): void
    {
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/saved-folders/1/lessons', ['lesson_id' => 10])
            ->assertOk()
            ->assertJsonPath('data.is_saved', true);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/saved-folders/1/lessons', ['lesson_id' => 10])
            ->assertOk()
            ->assertJsonPath('data.already_saved', true);

        self::assertSame(1, DB::table('saved_folder_lessons')
            ->where('saved_folder_id', 1)
            ->where('lesson_id', 10)
            ->count());
    }

    public function test_can_remove_lesson_from_folder(): void
    {
        $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/saved-folders/1/lessons/10')
            ->assertOk()
            ->assertJsonPath('data.already_removed', false);

        $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/saved-folders/1/lessons/10')
            ->assertOk()
            ->assertJsonPath('data.already_removed', true);

        $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/saved-folders/999/lessons/10')
            ->assertOk()
            ->assertJsonPath('data.already_removed', true);
    }

    public function test_removing_from_one_folder_keeps_the_other_membership(): void
    {
        $secondFolder = DB::table('saved_folders')->insertGetId([
            'user_id' => $this->user->id,
            'name' => 'الثاني',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('saved_folder_lessons')->insert([
            'saved_folder_id' => $secondFolder,
            'lesson_id' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->deleteJson('/api/v1/saved-folders/1/lessons/10')
            ->assertOk();

        self::assertFalse(DB::table('saved_folder_lessons')
            ->where('saved_folder_id', 1)
            ->where('lesson_id', 10)
            ->exists());
        self::assertTrue(DB::table('saved_folder_lessons')
            ->where('saved_folder_id', $secondFolder)
            ->where('lesson_id', 10)
            ->exists());
    }

    public function test_can_delete_saved_folder(): void
    {
        $response = $this->actingAs($this->user, 'api')->deleteJson('/api/v1/saved-folders/1');
        $this->assertNotEquals(404, $response->status());
    }
}
