<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

/**
 * A linked member who can authenticate into the workspace but is NOT attached to
 * the private project's team — they must not act on the project's task children.
 */
function nonTeamMemberActor(): User
{
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();

    return $user;
}

/**
 * A linked member attached to the given private project's team — may act.
 */
function projectTeamMemberActor(Project $project): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);
    $project->teams()->attach($team->id);

    return $user;
}

function privateTaskWithTeam(): Task
{
    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach(Team::factory()->create()->id);

    return Task::factory()->create(['project_id' => $project->id]);
}

it('forbids a non-team member from commenting on a private project task', function () {
    $task = privateTaskWithTeam();

    $this->actingAs(nonTeamMemberActor())
        ->post("/workspace/tasks/{$task->id}/comments", ['body' => 'sneaky'])
        ->assertForbidden();
});

it('forbids a non-team member from adding a note to a private project task', function () {
    $task = privateTaskWithTeam();

    $this->actingAs(nonTeamMemberActor())
        ->post("/workspace/tasks/{$task->id}/notes", ['body' => 'sneaky note'])
        ->assertForbidden();
});

it('forbids a non-team member from adding a contact to a private project task', function () {
    $task = privateTaskWithTeam();

    $this->actingAs(nonTeamMemberActor())
        ->post("/workspace/tasks/{$task->id}/contacts", ['name' => 'Sneaky Contact'])
        ->assertForbidden();
});

it('forbids a non-team member from assigning a member to a private project task', function () {
    $task = privateTaskWithTeam();
    $assignee = Member::factory()->create();

    $this->actingAs(nonTeamMemberActor())
        ->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $assignee->id])
        ->assertForbidden();
});

it('forbids a non-team member from commenting via the api/v1 twin', function () {
    $task = privateTaskWithTeam();

    $this->actingAs(nonTeamMemberActor())
        ->postJson("/api/v1/workspace/tasks/{$task->id}/comments", ['body' => 'sneaky'])
        ->assertForbidden();
});

it('allows a project team member to comment on a private project task', function () {
    $project = Project::factory()->create(['is_public' => false]);
    $task = Task::factory()->create(['project_id' => $project->id]);

    $this->actingAs(projectTeamMemberActor($project))
        ->post("/workspace/tasks/{$task->id}/comments", ['body' => 'legit comment'])
        ->assertRedirect();

    expect($task->comments()->where('body', 'legit comment')->exists())->toBeTrue();
});
