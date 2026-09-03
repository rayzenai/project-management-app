<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->company();

        return [
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'title' => $title,
            'title_np' => null,
            'description' => $this->faker->sentence(),
            'description_np' => null,
            'is_public' => false,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }

    public function public(): self
    {
        return $this->state(['is_public' => true]);
    }
}
