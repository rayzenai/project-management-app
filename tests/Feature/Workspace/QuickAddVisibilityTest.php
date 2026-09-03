<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

/**
 * A linked member who can authenticate into the workspace but is NOT on the
 * private project's team — must not be able to create tasks on it via quick-add.
 */
function quickAddOutsider(): User
{
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();

    return $user;
}

/**
 * A private project with a team the outsider is not on.
 */
function hiddenProject(): Project
{
    $project = Project::factory()->create(['is_public' => false]);
    $project->teams()->attach(Team::factory()->create()->id);

    return $project;
}

it('blocks quick-add via project_id targeting a project the user cannot view', function () {
    $hidden = hiddenProject();

    $this->actingAs(quickAddOutsider())
        ->from('/workspace')
        ->post('/workspace/quick-add', [
            'project_id' => $hidden->id,
            'title' => 'Sneaky task',
        ])->assertRedirect('/workspace');

    expect(Task::query()->where('project_id', $hidden->id)->count())->toBe(0);
});

it('blocks quick-add via #project token targeting a project the user cannot view', function () {
    $hidden = hiddenProject();
    $visible = Project::factory()->public()->create();

    $this->actingAs(quickAddOutsider())
        ->from('/workspace')
        ->post('/workspace/quick-add', [
            'project_id' => $visible->id,
            'title' => "Sneaky task #{$hidden->slug}",
        ])->assertRedirect('/workspace');

    expect(Task::query()->where('project_id', $hidden->id)->count())->toBe(0)
        ->and(Task::query()->where('project_id', $visible->id)->count())->toBe(0);
});

it('blocks quick-add via the api when the project is not viewable', function () {
    $hidden = hiddenProject();
    $user = quickAddOutsider();

    $token = $user->createToken('test')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/workspace/quick-add', [
            'project_id' => $hidden->id,
            'title' => 'Sneaky task',
        ])->assertForbidden();

    expect(Task::query()->where('project_id', $hidden->id)->count())->toBe(0);
});

it('allows a team member to quick-add to a private project they can view', function () {
    $project = Project::factory()->create(['is_public' => false]);

    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);
    $project->teams()->attach($team->id);

    $this->actingAs($user)
        ->post('/workspace/quick-add', [
            'project_id' => $project->id,
            'title' => 'Legit task',
        ])->assertRedirect();

    expect(Task::query()->where('project_id', $project->id)->where('title', 'Legit task')->exists())->toBeTrue();
});
