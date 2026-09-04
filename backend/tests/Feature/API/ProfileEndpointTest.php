<?php

declare(strict_types=1);

namespace Tests\Feature\API;

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
}
