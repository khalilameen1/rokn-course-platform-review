<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Controllers\API\CoursePurchaseController;
use App\Http\Middleware\RequireProductFeature;
use Illuminate\Http\Request;

/**
 * Feature tests covering Course Authorization and Enrollment API endpoints:
 * payment methods, authorizing access, access check, and student enrollment/order lists.
 */
class CourseEnrollmentEndpointTest extends ApiTestCase
{
    public function test_course_authorization_route_uses_the_atomic_purchase_controller(): void
    {
        $route = app('router')->getRoutes()->match(
            Request::create('/api/v1/courses/authorize', 'POST')
        );

        self::assertSame(
            CoursePurchaseController::class . '@authorizeCourse',
            $route->getActionName()
        );
    }

    public function test_retired_manual_payment_methods_endpoint_is_absent(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/courses/payment-methods')
            ->assertNotFound();
    }

    public function test_can_authorize_course(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/courses/authorize', [
            'course_id' => $this->courseId,
            'access_plan_code' => 'basic',
        ]);
        $this->assertNotEquals(404, $response->status());
    }

    public function test_course_purchase_requires_an_explicit_access_plan(): void
    {
        $this->withoutMiddleware(RequireProductFeature::class)
            ->actingAs($this->user, 'api')
            ->postJson('/api/v1/courses/authorize', [
                'course_id' => $this->courseId,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['access_plan_code']);
    }

    public function test_retired_parallel_course_access_endpoint_is_absent(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/courses/check-access', ['course_id' => $this->courseId])
            ->assertNotFound();
    }

    public function test_learning_courses_is_the_canonical_enrollment_read(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/learning/courses')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_retired_parallel_my_orders_endpoint_is_absent(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/courses/my-orders')
            ->assertNotFound();
    }

    public function test_retired_parallel_my_bills_endpoint_is_absent(): void
    {
        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/courses/my-bills')
            ->assertNotFound();
    }
}
