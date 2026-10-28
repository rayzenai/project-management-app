<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = 'Ministry of '.$this->faker->unique()->city();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        ];
    }

    /**
     * The organization teams, projects and members fall into when a test does
     * not name one: the first one, created on demand. Tests that are not
     * about tenancy then behave like the single-organization app they were
     * written for; tenancy tests create their own organizations explicitly.
     */
    public static function defaultId(): int
    {
        return (int) (Organization::query()->orderBy('id')->value('id') ?? Organization::factory()->create()->getKey());
    }
}
