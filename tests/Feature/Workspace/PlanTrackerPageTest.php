<?php

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Inertia\Testing\AssertableInertia;

it('shares the plan day counted from the oath date', function () {
    config(['government.oath_date' => Date::today()->subDays(161)->toDateString()]);
    $project = Project::factory()->create(['slug' => '100-day-plan', 'is_public' => true]);
    Task::factory()->for($project)->create(['status' => 'in_progress']);

    $this->actingAs(User::factory()->create());

    $this->get('/workspace/100-point-tracker')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('PlanTracker')
        ->where('planDay', 161)
        ->has('tasks', 1));
});

it('exposes percent_complete on the project index', function () {
    $project = Project::factory()->create(['is_public' => true]);
    Task::factory()->for($project)->create(['status' => 'done']);
    Task::factory()->for($project)->create(['status' => 'in_progress']);
    Task::factory()->for($project)->create(['status' => 'not_started']);

    $this->actingAs(User::factory()->create());

    $this->get('/workspace/projects')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('projects.0.percent_complete', 33));
});
