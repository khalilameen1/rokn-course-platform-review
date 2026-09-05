<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Feature tests covering User Profile API endpoints:
 * retrieving user profile information and updating account details.
 */
class ProfileEndpointTest extends ApiTestCase
{
    public function test_authenticated_user_can_view_profile(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/user/profile');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_authenticated_user_can_update_profile(): void
    {
        $response = $this->actingAs($this->user, 'api')->putJson('/api/v1/user/profile', [
            'name' => 'Updated User',
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_social_identity_cannot_be_changed_through_profile_settings(): void
    {
        $this->actingAs($this->user, 'api')->putJson('/api/v1/user/profile', [
            'email' => 'other@rokn.com',
            'phone' => '201000000000',
        ])->assertUnprocessable();
    }

    public function test_profile_update_has_no_parallel_legacy_route(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/update_profile', ['name' => 'Legacy route probe'])
            ->assertNotFound();
    }

    public function test_account_identity_fields_are_updated_together(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/user/profile', [
            'name' => 'Rokn Learner',
            'job_title' => 'Product Designer',
            'portfolio_slug' => 'rokn-learner-' . $this->user->id,
            'portfolio_headline' => 'Product Designer',
        ]);
        $serverOwnedSlug = (string) $response->json('data.portfolio_slug');

        $response
            ->assertOk()
            ->assertJsonPath('data.name', 'Rokn Learner')
            ->assertJson(fn ($json) => $json
                ->whereType('data.portfolio_slug', 'string')
                ->where('data.portfolio_url', \App\Support\RoknPublicUrl::portfolio(
                    $serverOwnedSlug
                ))
                ->etc())
            ->assertJsonPath('data.portfolio_headline', 'Product Designer');

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Rokn Learner',
            'portfolio_slug' => $serverOwnedSlug,
            'portfolio_headline' => 'Product Designer',
        ]);
    }

    public function test_editing_a_social_display_name_replaces_legacy_localized_values_without_rewriting_issued_certificates(): void
    {
        $this->user->forceFill([
            'name' => 'Provider Name',
            'name_ar' => 'اسم قديم',
            'name_en' => 'Old Name',
            'profile_revision' => 4,
        ])->save();
        DB::table('certificates')->insert([
            'public_id' => (string) Str::uuid(),
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'holder_name' => 'اسم الشهادة الصادرة',
            'course_name' => 'دورة تجريبية',
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'تقديرًا لإتمام متطلبات كورس',
            'image_path' => 'certificates/already-issued.png',
            'status' => 'active',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/profile', [
            'client_request_id' => (string) Str::uuid(),
            'expected_profile_revision' => 4,
            'name' => 'الاسم الذي اخترته',
        ])->assertOk()
            ->assertJsonPath('data.name', 'الاسم الذي اخترته')
            ->assertJsonPath('data.profile_revision', 5);

        $fresh = $this->user->fresh();
        self::assertSame('الاسم الذي اخترته', $fresh->getRawOriginal('name'));
        self::assertNull($fresh->getRawOriginal('name_ar'));
        self::assertNull($fresh->getRawOriginal('name_en'));
        self::assertSame('اسم الشهادة الصادرة', DB::table('certificates')
            ->where('user_id', $this->user->id)
            ->value('holder_name'));
    }

    public function test_display_name_contract_matches_the_name_limit_used_for_new_certificates(): void
    {
        $original = $this->user->getRawOriginal('name');

        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/profile', [
            'client_request_id' => (string) Str::uuid(),
            'expected_profile_revision' => 0,
            'name' => str_repeat('أ', 121),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('name');

        self::assertSame($original, $this->user->fresh()->getRawOriginal('name'));
    }
}
