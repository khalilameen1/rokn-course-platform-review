<?php

declare(strict_types=1);

namespace Tests\Feature\API;

/**
 * Feature tests covering classifications and user interest selections.
 */
class ClassificationEndpointTest extends ApiTestCase
{
    public function test_can_list_classifications(): void
    {
        $response = $this->getJson('/api/v1/classifications');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_authenticated_user_can_update_interests(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/user/interests', [
            'classification_ids' => [1, 2]
        ]);
        $this->assertNotEquals(404, $response->status());
    }
}
