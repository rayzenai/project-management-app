<?php

namespace Database\Factories;

use App\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Designation>
 */
class DesignationFactory extends Factory
{
    protected $model = Designation::class;

    public function definition(): array
    {
        return [
            'organization_id' => fn (): int => OrganizationFactory::defaultId(),
            'name' => $this->faker->unique()->jobTitle(),
            'rank' => $this->faker->numberBetween(1, 200),
        ];
    }
}
