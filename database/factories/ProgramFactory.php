<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Program>
 */
class ProgramFactory extends Factory
{
    protected $model = Program::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->unique()->company(),
            'description' => $this->faker->optional()->sentence(),
            'manager_id' => fn (array $attributes): int => (int) Member::factory()->create([
                'organization_id' => $attributes['organization_id'],
            ])->getKey(),
        ];
    }
}
