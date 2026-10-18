<?php

namespace Database\Factories;

use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

class LessonFactory extends Factory
{
    protected $model = Lesson::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'title_ar' => $this->faker->sentence(4),
            'title_en' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'description_ar' => $this->faker->paragraph(),
            'description_en' => $this->faker->paragraph(),
            'is_opened' => true,
            'video_link' => 'https://www.youtube.com/watch?v='.$this->faker->lexify('???????????'),
            'video_source_type' => 'youtube',
            'priority' => $this->faker->numberBetween(1, 10),
            'list_id' => null,
        ];
    }
}
