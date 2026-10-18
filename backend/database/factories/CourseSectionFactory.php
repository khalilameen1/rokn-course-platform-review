<?php

namespace Database\Factories;

use App\Models\CourseSection;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourseSectionFactory extends Factory
{
    protected $model = CourseSection::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'title_ar' => $this->faker->sentence(3),
            'title_en' => $this->faker->sentence(3),
            'course_id' => null,
            'module_id' => null,
            'section_type' => 'lesson',
            'sectionable_type' => null,
            'sectionable_id' => null,
            'order' => 1,
        ];
    }

    public function lesson(): static
    {
        return $this->state(['section_type' => 'lesson']);
    }

    public function project(): static
    {
        return $this->state(['section_type' => 'project']);
    }
}
