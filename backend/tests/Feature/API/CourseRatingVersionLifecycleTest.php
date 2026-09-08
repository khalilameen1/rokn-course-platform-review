<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Support\Facades\DB;

final class CourseRatingVersionLifecycleTest extends ApiTestCase
{
    public function test_http_rating_versions_survive_delete_restore_and_transport_retries(): void
    {
        $this->actingAs($this->user, 'api');
        $this->postJson('/api/v1/course-codes/redeem', ['code' => 'TESTCODE'])->assertOk();
        DB::table('lesson_watch_evidence')->insert([
            'user_id' => $this->user->id, 'lesson_id' => 10, 'course_section_id' => $this->sectionId,
            'duration_seconds' => 900, 'verified_seconds' => 900, 'last_position_seconds' => 900,
            'last_heartbeat_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $url = '/api/v1/courses/'.$this->courseId.'/rate';
        $this->assertDetails(0, null);
        $first = ['rating' => 4, 'comment' => 'تعليق أول', 'version' => 0];
        foreach ([1, 2] as $attempt) {
            $this->postJson($url, $first)->assertOk()
                ->assertJsonPath('data.rating', 4)->assertJsonPath('data.version', 1)
                ->assertJsonPath('data.ratings_count', 1);
        }
        $rowId = DB::table('course_ratings')->value('id');
        $this->assertDetails(1, 4);
        $this->postJson($url, ['rating' => 5, 'comment' => 'تعديل', 'version' => 1])
            ->assertOk()->assertJsonPath('data.version', 2);
        $this->postJson($url, ['rating' => 2, 'version' => 1])
            ->assertStatus(409)->assertJsonPath('data.rating', 5)->assertJsonPath('data.version', 2);
        $this->assertDetails(2, 5);

        foreach ([1, 2] as $attempt) {
            $this->deleteJson($url, ['version' => 2])->assertOk()
                ->assertJsonPath('data.rating', null)->assertJsonPath('data.version', 3)
                ->assertJsonPath('data.ratings_count', 0)->assertJsonPath('data.average_rating', null);
        }
        $this->assertDetails(3, null);
        self::assertNotNull(DB::table('course_ratings')->value('deleted_at'));

        $restored = ['rating' => 3, 'comment' => 'تقييم مستعاد', 'version' => 3];
        foreach ([1, 2] as $attempt) {
            $this->postJson($url, $restored)->assertOk()
                ->assertJsonPath('data.rating', 3)->assertJsonPath('data.version', 4)
                ->assertJsonPath('data.ratings_count', 1);
        }
        $this->deleteJson($url, ['version' => 2])->assertStatus(409)
            ->assertJsonPath('data.rating', 3)->assertJsonPath('data.version', 4);
        $this->assertDetails(4, 3);
        self::assertSame(1, DB::table('course_ratings')->count());
        self::assertSame($rowId, DB::table('course_ratings')->value('id'));
        self::assertNull(DB::table('course_ratings')->value('deleted_at'));
    }

    private function assertDetails(int $version, ?int $rating): void
    {
        $details = $this->getJson('/api/v1/courses/'.$this->courseId.'/details')
            ->assertOk()->assertJsonPath('data.rating_eligibility.version', $version);
        if ($rating === null) {
            $details->assertJsonPath('data.user_rating', null);
        } else {
            $details->assertJsonPath('data.user_rating.version', $version)
                ->assertJsonPath('data.user_rating.rating', $rating);
        }
    }
}
