<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

it('reports an overdue stat for incomplete past-deadline tasks', function () {
    $project = Project::factory()->create(['is_public' => true]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => now()->subDays(3)]);
    Task::factory()->for($project)->create(['status' => 'in_progress', 'deadline_at' => now()->addDays(3)]);
    Task::factory()->for($project)->create(['status' => 'done', 'deadline_at' => now()->subDays(3)]);

    $this->actingAs(User::factory()->create());

    $this->get('/workspace')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('stats.overdue', 1));
});
