<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'user_id' => 1,
            'name' => $this->faker->company(),
            'phone' => $this->faker->phoneNumber(),
        ];
    }
}
