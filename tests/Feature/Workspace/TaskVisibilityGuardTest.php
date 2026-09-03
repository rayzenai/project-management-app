<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

/**
 * A linked member who is NOT attached to the private project's team. They can
 * authenticate into the workspace but must not see or act on the project's tasks.
 */
function nonMemberActor(): User
{
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();

    return $user;
}

/**
 * A linked member attached to the given private project's team — may see and act.
 */
function teamMemberActor(Project $project): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);
    $project->teams()->attach($team->id);

    return $user;
}

function privateProjectWithTeam(): Project
{
    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach(Team::factory()->create()->id);

    return $project;
}

it('forbids a non-member from previewing a private project task', function () {
    $project = privateProjectWithTeam();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs(nonMemberActor())
        ->getJson("/workspace/tasks/{$task->id}/preview")
        ->assertForbidden();
});

it('allows a team member to preview a private project task', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs(teamMemberActor($project))
        ->getJson("/workspace/tasks/{$task->id}/preview")
        ->assertOk();
});

it('forbids a non-member from creating a task in a private project', function () {
    $project = privateProjectWithTeam();

    $this->actingAs(nonMemberActor())
        ->post("/workspace/projects/{$project->slug}/tasks", ['title' => 'Sneaky task'])
        ->assertForbidden();
});

it('forbids a non-member from updating a private project task', function () {
    $project = privateProjectWithTeam();
    $task = Task::factory()->create(['project_id' => $project->id, 'status' => 'in_progress']);

    $this->actingAs(nonMemberActor())
        ->patch("/workspace/projects/{$project->slug}/tasks/{$task->slug}", ['title' => 'Hijacked'])
        ->assertForbidden();
});

it('forbids a non-member from deleting a private project task', function () {
    $project = privateProjectWithTeam();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs(nonMemberActor())
        ->delete("/workspace/projects/{$project->slug}/tasks/{$task->slug}")
        ->assertForbidden();
});

it('forbids a non-member from reordering tasks in a private project', function () {
    $project = privateProjectWithTeam();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs(nonMemberActor())
        ->post("/workspace/projects/{$project->slug}/tasks/reorder", ['task_ids' => [$task->id]])
        ->assertForbidden();
});

it('allows a team member to create a task in a private project', function () {
    $project = Project::factory()->create(['is_public' => false]);

    $this->actingAs(teamMemberActor($project))
        ->post("/workspace/projects/{$project->slug}/tasks", ['title' => 'Legit task'])
        ->assertRedirect();

    expect(Task::query()->where('title', 'Legit task')->where('project_id', $project->id)->exists())->toBeTrue();
});
