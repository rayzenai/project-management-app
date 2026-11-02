<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;

it('reports an overdue stat for incomplete past-deadline tasks', function () {
    $project = Project::factory()->create(['is_public' => true]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => now()->subDays(3)]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => now()->addDays(3)]);
    Task::factory()->for($project)->create(['status' => 'done', 'deadline_at' => now()->subDays(3)]);

    $this->actingAs(User::factory()->withMember()->create(), 'sanctum');

    $this->getJson('/api/v1/workspace/home')->assertOk()->assertJsonPath('stats.overdue', 1);
});
