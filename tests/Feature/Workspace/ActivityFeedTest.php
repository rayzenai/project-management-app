<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/**
 * ProjectActivityRecorder always writes is_public = false, so any feed that
 * filters on ->public() is permanently empty. These guard both feeds that used
 * to do exactly that.
 */
it('shows observer-written activity on the activity tab', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $project = Project::factory()->create(['is_public' => true]);

    $task = Task::factory()->for($project)->create();

    $this->actingAs($user)->get('/workspace/notifications?tab=activity')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'activity')
            ->has('activity.data')
            ->where('activity.data.0.task_slug', $task->slug));
});

it('keeps another team private project out of the activity tab', function () {
    config(['project-management.super_admins' => []]);

    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();

    $foreign = Project::factory()->create(['is_public' => false]);
    $foreign->teams()->attach(Team::factory()->create()->id);
    Task::factory()->for($foreign)->create();

    $this->actingAs($user)->get('/workspace/notifications?tab=activity')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('activity.data', 0));
});

it('shows observer-written activity in the task peek', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $project = Project::factory()->create(['is_public' => true]);
    $task = Task::factory()->for($project)->create();

    $activity = $this->getJson("/workspace/tasks/{$task->id}/preview")
        ->assertSuccessful()
        ->json('data.activity');

    expect($activity)->not->toBeEmpty()
        ->and(collect($activity)->pluck('description')->implode(' '))->toContain('created');
});
