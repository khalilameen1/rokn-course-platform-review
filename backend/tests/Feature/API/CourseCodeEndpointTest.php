<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Support\Facades\DB;

/**
 * Feature tests covering Course Code API endpoints:
 * redeeming codes, checking code validity, and listing user redeemed codes.
 */
class CourseCodeEndpointTest extends ApiTestCase
{
    public function test_can_redeem_course_code(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/course-codes/redeem', ['code' => 'TESTCODE']);
        $response->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.learning_access', true);
    }

    public function test_can_check_course_code(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/course-codes/check', ['code' => 'TESTCODE']);
        $response->assertOk()->assertJsonPath('data.can_use', true)
            ->assertJsonPath('data.code', 'TESTCODE');
    }

    public function test_grant_redemption_returns_watch_only_access_and_retry_does_not_charge(): void
    {
        DB::table('course_codes')->where('code', 'TESTCODE')->update(['is_grant' => true]);
        $input = ['code' => 'TESTCODE', 'course_id' => $this->courseId];
        $this->actingAs($this->user, 'api')->postJson('/api/v1/course-codes/redeem', $input)
            ->assertOk()
            ->assertJsonPath('data.learning_access', true)
            ->assertJsonPath('data.access_type', 'scholarship')
            ->assertJsonPath('data.chat_available', false)
            ->assertJsonPath('data.projects_available', false)
            ->assertJsonPath('data.certificate_available', false);
        $this->actingAs($this->user, 'api')->postJson('/api/v1/course-codes/redeem', $input)
            ->assertOk()
            ->assertJsonPath('data.already_enrolled', true)
            ->assertJsonPath('data.learning_access', true)
            ->assertJsonPath('data.projects_available', false);
        $this->assertDatabaseHas('course_codes', ['code' => 'TESTCODE', 'used_count' => 1]);
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'payment_method' => \App\Models\Order::PAYMENT_METHOD_COURSE_CODE,
            'final_amount' => 0,
        ]);
    }

    public function test_can_view_my_codes(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/course-codes/my-codes');
        $response->assertOk()->assertJsonPath('data', []);
    }

    public function test_wrong_course_does_not_consume_grant_code(): void
    {
        DB::table('course_codes')->where('code', 'TESTCODE')->update(['is_grant' => true]);
        $otherCourseId = DB::table('courses')->insertGetId([
            'name_ar' => 'كورس مختلف',
            'name_en' => 'Different course',
            'price' => 100,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')->postJson(
            '/api/v1/course-codes/redeem',
            ['code' => 'TESTCODE', 'course_id' => $otherCourseId]
        );

        $response->assertStatus(409)
            ->assertJsonPath('code', 'course_code_course_mismatch');
        $this->assertDatabaseHas('course_codes', [
            'code' => 'TESTCODE',
            'used_count' => 0,
        ]);
        $this->assertDatabaseCount('course_grant_claims', 0);
    }

    public function test_one_account_can_claim_only_one_course_grant(): void
    {
        DB::table('course_codes')->where('code', 'TESTCODE')->update(['is_grant' => true]);
        $secondCourseId = DB::table('courses')->insertGetId([
            'name_ar' => 'كورس ثان',
            'name_en' => 'Second course',
            'price' => 100,
            'active' => true,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $secondCourseId,
            'title_ar' => 'قسم تجريبي',
            'title_en' => 'Test section',
            'section_type' => 'lesson',
            'order' => 1,
            'sort_order' => 1,
            'is_free' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_codes')->insert([
            'code' => 'SECOND-GRANT',
            'type' => 'course',
            'course_id' => $secondCourseId,
            'is_grant' => true,
            'is_active' => true,
            'used_count' => 0,
            'max_uses' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')->postJson('/api/v1/course-codes/redeem', [
            'code' => 'TESTCODE',
            'course_id' => $this->courseId,
        ])->assertOk();

        $this->actingAs($this->user, 'api')->postJson('/api/v1/course-codes/redeem', [
            'code' => 'SECOND-GRANT',
            'course_id' => $secondCourseId,
        ])->assertStatus(409)->assertJsonPath('code', 'grant_already_claimed');

        $this->assertDatabaseCount('course_grant_claims', 1);
        $this->assertDatabaseHas('course_codes', [
            'code' => 'SECOND-GRANT',
            'used_count' => 0,
        ]);
    }

    public function test_multiple_lesson_code_is_serialized_without_relation_errors(): void
    {
        $codeId = DB::table('course_codes')->insertGetId([
            'code' => 'MULTI-LESSON',
            'type' => 'multiple_lessons',
            'course_id' => $this->courseId,
            'is_active' => true,
            'used_count' => 1,
            'max_uses' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_code_usages')->insert([
            'course_code_id' => $codeId,
            'user_id' => $this->user->id,
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/course-codes/my-codes');

        $response->assertOk()
            ->assertJsonPath('data.0.code', 'MULTI-LESSON')
            ->assertJsonPath('data.0.lessons', []);
    }
}
