<?php

declare(strict_types=1);

namespace Tests\Feature\API;

/**
 * Feature tests covering Grade/Level API endpoints:
 * listing grades, viewing specific grade details, and listing courses by grade.
 */
class GradeEndpointTest extends ApiTestCase
{
    public function test_can_list_grades(): void
    {
        $this->getJson('/api/v1/grades')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_can_view_grade_details(): void
    {
        $this->getJson("/api/v1/grades/{$this->gradeId}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->gradeId);
    }

    public function test_can_list_courses_by_grade(): void
    {
        $this->getJson("/api/v1/grades/{$this->gradeId}/courses")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.grade.id', $this->gradeId)
            ->assertJsonPath('data.courses.0.id', $this->courseId);
    }
}
