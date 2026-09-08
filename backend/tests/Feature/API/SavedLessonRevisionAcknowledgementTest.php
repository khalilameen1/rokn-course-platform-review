<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\CourseAuthoringRevision;
use App\Models\Lesson;
use Illuminate\Support\Facades\DB;

final class SavedLessonRevisionAcknowledgementTest extends ApiTestCase
{
    private function publishReplacement(): void
    {
        // A retained lesson and the surviving published clone share learner
        // state, while the currently mounted mobile reel still requests 10.
        $replacement = (array) DB::table('lessons')->where('id', 10)->first();
        $replacement['id'] = 20;
        DB::table('lessons')->insert($replacement);
        DB::table('course_sections')->where('id', $this->sectionId)->update([
            'sectionable_id' => 20,
        ]);
        $revisionId = DB::table('course_authoring_revisions')->insertGetId([
            'canonical_course_id' => $this->courseId,
            'revision_course_id' => $this->courseId,
            'base_authoring_version' => 1,
            'published_authoring_version' => 2,
            'status' => CourseAuthoringRevision::ARCHIVED,
            'clone_key' => '11111111-1111-4111-8111-111111111111',
            'published_at' => now(),
        ]);
        DB::table('course_authoring_revision_entities')->insert([
            'course_authoring_revision_id' => $revisionId,
            'entity_type' => Lesson::class,
            'source_entity_id' => 10,
            'revision_entity_id' => 20,
            'survives_publish' => true,
            'carries_learner_state' => true,
            'learner_root_entity_id' => 10,
        ]);
        DB::table('saved_folder_lessons')->delete();
    }

    public function test_historical_reel_save_acknowledges_requested_identity_after_committing_current_membership(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-09-09T00:00:00+00:00'));
        $this->publishReplacement();

        $response = $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/saved-folders/1/lessons', ['lesson_id' => 10])
            ->assertOk();

        // Prove the mutation really succeeded before asserting the client ACK.
        $this->assertDatabaseHas('saved_folder_lessons', [
            'saved_folder_id' => 1, 'lesson_id' => 20,
        ]);
        $this->assertDatabaseMissing('saved_folder_lessons', [
            'saved_folder_id' => 1, 'lesson_id' => 10,
        ]);
        $response->assertExactJson(json_decode(file_get_contents(
            base_path('../mobile/__tests__/fixtures/savedLessonRevisionAcknowledgement.json')
        ), true, 512, JSON_THROW_ON_ERROR));
        $response->assertJsonPath('data.is_saved', true)
            ->assertJsonPath('data.folder_id', 1)
            ->assertJsonPath('data.lesson_id', 10);

        $this->postJson('/api/v1/saved-folders/1/lessons', ['lesson_id' => 10])
            ->assertOk()
            ->assertJsonPath('data.lesson_id', 10)
            ->assertJsonPath('data.already_saved', true);
        self::assertSame(1, DB::table('saved_folder_lessons')->where('lesson_id', 20)->count());

        // The open historical reel retains its bookmark, while reopening the
        // saved library uses the actual published lesson and only one row.
        $this->getJson('/api/v1/saved-lessons/state?lesson_ids[]=10&lesson_ids[]=20')
            ->assertOk()
            ->assertJsonPath('data.saved_lesson_ids', [10, 20]);
        $this->getJson('/api/v1/saved-folders/1/lessons')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.lessons.0.id', 20);
        $this->deleteJson('/api/v1/saved-folders/1/lessons/10')->assertOk();
        $this->assertDatabaseCount('saved_folder_lessons', 0);
    }

    public function test_current_reel_save_and_historical_retry_share_one_membership(): void
    {
        $this->publishReplacement();

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/saved-folders/1/lessons', ['lesson_id' => 20])
            ->assertOk()
            ->assertJsonPath('data.lesson_id', 20)
            ->assertJsonPath('data.already_saved', false);
        $this->postJson('/api/v1/saved-folders/1/lessons', ['lesson_id' => 10])
            ->assertOk()
            ->assertJsonPath('data.lesson_id', 10)
            ->assertJsonPath('data.already_saved', true);
        $this->assertDatabaseCount('saved_folder_lessons', 1);
        $this->assertDatabaseHas('saved_folder_lessons', ['saved_folder_id' => 1, 'lesson_id' => 20]);
    }

    public function test_nonexistent_lesson_does_not_become_a_successful_alias(): void
    {
        $this->publishReplacement();

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/saved-folders/1/lessons', ['lesson_id' => 999])
            ->assertUnprocessable()
            ->assertJsonPath('data', null);
        $this->assertDatabaseCount('saved_folder_lessons', 0);
    }

    public function test_historical_identity_does_not_bypass_current_lesson_access(): void
    {
        $this->publishReplacement();
        DB::table('lessons')->where('id', 20)->update(['is_opened' => false]);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/saved-folders/1/lessons', ['lesson_id' => 10])
            ->assertForbidden()
            ->assertJsonPath('data', null);
        $this->assertDatabaseCount('saved_folder_lessons', 0);
    }
}
