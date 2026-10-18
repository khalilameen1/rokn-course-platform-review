<?php

namespace Database\Factories;

use App\Models\Grade;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class GradeFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Grade::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        $types = ['preparatory', 'secondary', 'primary', 'university', 'general'];
        $type = $this->faker->randomElement($types);
        $level = $this->faker->numberBetween(1, 3);
        $names = [
            'preparatory' => "Grade {$level}",
            'secondary' => "Secondary {$level}",
            'primary' => "Primary {$level}",
            'university' => "University Year {$level}",
            'general' => "General Level {$level}",
        ];
        $name = $names[$type];

        return [
            'tenant_id' => Tenant::factory(),
            'type' => $type,
            'name_ar' => $name,
            'name_en' => $name,
            'description_ar' => $this->faker->sentence(),
            'description_en' => $this->faker->sentence(),
            'country' => 'egypt',
        ];
    }

    /**
     * Indicate that the grade is preparatory.
     *
     * @return Factory
     */
    public function preparatory()
    {
        return $this->state(function (array $attributes) {
            $level = $attributes['level'] ?? $this->faker->numberBetween(1, 3);

            return [
                'type' => 'preparatory',
                'name_ar' => "Grade {$level}",
                'name_en' => "Grade {$level}",
            ];
        });
    }

    /**
     * Indicate that the grade is secondary.
     *
     * @return Factory
     */
    public function secondary()
    {
        return $this->state(function (array $attributes) {
            $level = $attributes['level'] ?? $this->faker->numberBetween(1, 3);

            return [
                'type' => 'secondary',
                'name_ar' => "Secondary {$level}",
                'name_en' => "Secondary {$level}",
            ];
        });
    }

    /**
     * Indicate that the grade is primary.
     *
     * @return Factory
     */
    public function primary()
    {
        return $this->state(function (array $attributes) {
            $level = $attributes['level'] ?? $this->faker->numberBetween(1, 6);

            return [
                'type' => 'primary',
                'name_ar' => "Primary {$level}",
                'name_en' => "Primary {$level}",
            ];
        });
    }

    /**
     * Indicate that the grade is university.
     *
     * @return Factory
     */
    public function university()
    {
        return $this->state(function (array $attributes) {
            $level = $attributes['level'] ?? $this->faker->numberBetween(1, 4);

            return [
                'type' => 'university',
                'name_ar' => "University Year {$level}",
                'name_en' => "University Year {$level}",
            ];
        });
    }

    /**
     * Indicate that the grade is general.
     *
     * @return Factory
     */
    public function general()
    {
        return $this->state(function (array $attributes) {
            $level = $attributes['level'] ?? $this->faker->numberBetween(1, 3);

            return [
                'type' => 'general',
                'name_ar' => "General Level {$level}",
                'name_en' => "General Level {$level}",
            ];
        });
    }
}
