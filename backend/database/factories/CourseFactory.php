<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourseFactory extends Factory
{
    protected $model = Course::class;

    public function definition(): array
    {
        return [
            'name_ar' => $this->faker->sentence(3),
            'name_en' => $this->faker->sentence(3),
            'description_ar' => $this->faker->paragraph(),
            'description_en' => $this->faker->paragraph(),
            'price' => $this->faker->numberBetween(0, 500),
            'price_before_discount' => 0,
            'currency' => 'جنيه',
            'grade_id' => null,
            'teacher_id' => null,
        ];
    }

    public function free(): static
    {
        return $this->state(['price' => 0]);
    }
}
