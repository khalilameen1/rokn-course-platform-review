<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'requirements_text_ar' => $this->faker->paragraph(),
            'requirements_text_en' => $this->faker->paragraph(),
            'is_graduation_project' => false,
        ];
    }

    public function graduation(): static
    {
        return $this->state([
            'is_graduation_project' => true,
        ]);
    }
}
