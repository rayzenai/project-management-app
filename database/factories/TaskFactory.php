<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    public function definition(): array
    {
        $categories = array_keys(config('government.categories'));
        $statuses = array_keys(config('project-management.statuses'));
        $deadlines = array_keys(config('government.deadline_types'));

        return [
            'project_id' => fn () => $this->resolveDefaultProjectId(),
            'item_number' => $this->faker->unique()->numberBetween(1, 9999),
            'title' => $this->faker->sentence(4),
            'description' => $this->faker->paragraph(),
            'category' => $this->faker->randomElement($categories),
            'deadline_type' => $this->faker->randomElement($deadlines),
            'status' => $this->faker->randomElement($statuses),
            'status_note' => $this->faker->optional()->sentence(),
            'responsible_ministry' => $this->faker->optional()->company(),
            'source_url' => $this->faker->optional()->url(),
            'status_updated_at' => now(),
        ];
    }

    protected function resolveDefaultProjectId(): int
    {
        $id = DB::table('projects')->where('slug', '100-day-plan')->value('id');

        return $id ?? Project::factory()->public()->create(['slug' => '100-day-plan', 'title' => 'Government 100-Day Plan'])->id;
    }
}
