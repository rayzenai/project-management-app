<?php

use App\Models\GitConnection;
use App\Models\GitPullRequest;
use App\Models\GitRepository;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\User;
use App\Services\ProjectActivityRecorder;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * Git rows in the project activity (a linked repository, a pull request on a
 * task) name repositories and pull-request titles, so they follow the code
 * rule (ProjectActivity::scopeCodeVisibleTo): the team, the office admins and
 * super-admins. Other viewers of a public project see the rest of the
 * activity but not these rows. Every reader of activity goes through it.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    // Joining a team queues an access sync of its repositories; there is no forge here.
    Queue::fake();

    $this->organization = Organization::factory()->create(['slug' => 'sdoc']);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'key' => 'TP', 'is_public' => true]);
    $this->task = Task::factory()->for($this->project)->create(['title' => 'Fix login']);

    $connection = GitConnection::query()->create([
        'organization_id' => $this->organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'sdoc',
        'token' => 'bot-token-never-shown',
        'webhook_id' => 99,
        'webhook_secret' => 'hook-secret-never-shown',
    ]);

    $repo = GitRepository::query()->create([
        'git_connection_id' => $connection->id,
        'project_id' => $this->project->id,
        'forge_id' => 1,
        'owner' => 'sdoc',
        'name' => 'secret-repo',
        'full_name' => 'sdoc/secret-repo',
        'html_url' => 'https://git.example/sdoc/secret-repo',
        'clone_url' => 'https://git.example/sdoc/secret-repo.git',
        'ssh_url' => 'git@git.example:sdoc/secret-repo.git',
        'default_branch' => 'main',
    ]);

    $pr = GitPullRequest::query()->create([
        'git_repository_id' => $repo->id,
        'number' => 7,
        'title' => 'Hide the admin panel',
        'state' => GitPullRequest::STATE_OPEN,
        'url' => 'https://git.example/sdoc/secret-repo/pulls/7',
        'head_branch' => 'tp-1-hide',
        'author_username' => 'bikash',
        'opened_at' => '2026-10-27 09:00:00',
    ]);

    ProjectActivityRecorder::record(
        taskId: $this->task->id,
        subject: $pr,
        action: ProjectActivity::ACTION_CREATED,
        description: 'Pull request #7 opened: Hide the admin panel',
        system: true,
    );

    $this->team = $this->project->ensureTeam();
});

function gitActivityActor(string $role, Project $project): User
{
    if ($role === 'super-admin') {
        return User::factory()->create(['email' => 'boss@example.com']);
    }

    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create([
        'organization_id' => $project->organization_id,
        'is_office_admin' => $role === 'office admin',
    ]);

    if ($role === 'team member') {
        $project->ensureTeam()->members()->attach($member->id, ['role' => 'member']);
    }

    if ($role === 'manager') {
        $project->ensureTeam()->members()->attach($member->id, ['role' => 'leader']);
    }

    return $user;
}

/** @param  iterable<mixed>  $rows */
function gitActivityMentionsGit(iterable $rows): bool
{
    return str_contains(json_encode($rows, JSON_THROW_ON_ERROR), 'Pull request #7')
        || str_contains(json_encode($rows, JSON_THROW_ON_ERROR), 'Linked repository');
}

/** Whether the outsider still sees the non-git rows, so an empty list can't pass for hidden. */
function gitActivityMentionsTask(iterable $rows): bool
{
    return str_contains(json_encode($rows, JSON_THROW_ON_ERROR), 'created');
}

dataset('code viewers', ['team member', 'manager', 'office admin', 'super-admin']);

it('hides git rows from the web activity feed for a public-project outsider', function () {
    $outsider = gitActivityActor('outsider', $this->project);

    $this->actingAs($outsider)->get('/workspace/notifications?tab=activity')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('activity.data')
            ->where('activity.data', fn ($rows) => ! gitActivityMentionsGit($rows) && gitActivityMentionsTask($rows)));
});

it('shows git rows in the web activity feed to code viewers', function (string $role) {
    $this->actingAs(gitActivityActor($role, $this->project))->get('/workspace/notifications?tab=activity')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activity.data', fn ($rows) => gitActivityMentionsGit($rows)));
})->with('code viewers');

it('hides git rows from the task peek and the API preview for an outsider', function () {
    $outsider = gitActivityActor('outsider', $this->project);

    $web = $this->actingAs($outsider)->getJson("/workspace/tasks/{$this->task->id}/preview")->assertSuccessful()->json('data.activity');
    expect(gitActivityMentionsGit($web))->toBeFalse()->and(gitActivityMentionsTask($web))->toBeTrue();

    Sanctum::actingAs($outsider, ['*']);
    $api = $this->getJson("/api/v1/workspace/tasks/{$this->task->id}/preview")->assertSuccessful()->json('data.activity');
    expect(gitActivityMentionsGit($api))->toBeFalse()->and(gitActivityMentionsTask($api))->toBeTrue();
});

it('shows git rows in the task peek and the API preview to code viewers', function (string $role) {
    $user = gitActivityActor($role, $this->project);

    $web = $this->actingAs($user)->getJson("/workspace/tasks/{$this->task->id}/preview")->assertSuccessful()->json('data.activity');
    expect(gitActivityMentionsGit($web))->toBeTrue();

    Sanctum::actingAs($user, ['*']);
    $api = $this->getJson("/api/v1/workspace/tasks/{$this->task->id}/preview")->assertSuccessful()->json('data.activity');
    expect(gitActivityMentionsGit($api))->toBeTrue();
})->with('code viewers');

it('hides git rows from the API dashboard for an outsider and shows them to code viewers', function () {
    Sanctum::actingAs(gitActivityActor('outsider', $this->project), ['*']);
    $rows = $this->getJson('/api/v1/workspace/dashboard')->assertSuccessful()->json('recent_activity');
    expect(gitActivityMentionsGit($rows))->toBeFalse()->and(gitActivityMentionsTask($rows))->toBeTrue();

    foreach (['team member', 'office admin', 'super-admin'] as $role) {
        Sanctum::actingAs(gitActivityActor($role, $this->project), ['*']);
        $rows = $this->getJson('/api/v1/workspace/dashboard')->assertSuccessful()->json('recent_activity');
        expect(gitActivityMentionsGit($rows))->toBeTrue();
    }
});

it('shows git rows in the project progress report, web and API, to those who may open it', function (string $role) {
    $user = gitActivityActor($role, $this->project);

    $this->actingAs($user)->get("/workspace/projects/{$this->project->slug}/progress")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('progress.activity', fn ($rows) => gitActivityMentionsGit($rows)));

    Sanctum::actingAs($user, ['*']);
    $rows = $this->getJson("/api/v1/workspace/projects/{$this->project->slug}/progress")->assertSuccessful()->json('data.activity');
    expect(gitActivityMentionsGit($rows))->toBeTrue();
})->with(['manager', 'office admin', 'super-admin']);

it('shows git rows to the manager of the project\'s program, who is not on its team', function () {
    $user = gitActivityActor('outsider', $this->project);
    $program = Program::factory()->create([
        'organization_id' => $this->organization->id,
        'manager_id' => Member::query()->where('user_id', $user->id)->value('id'),
    ]);
    $this->project->update(['program_id' => $program->id]);

    $this->actingAs($user)->get('/workspace/notifications?tab=activity')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activity.data', fn ($rows) => gitActivityMentionsGit($rows)));

    $this->actingAs($user)->get("/workspace/projects/{$this->project->slug}/progress")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('progress.activity', fn ($rows) => gitActivityMentionsGit($rows)));
});

it('hides git rows when the organization has no git connection any more', function () {
    GitConnection::query()->delete();

    $this->actingAs(gitActivityActor('office admin', $this->project))->get('/workspace/notifications?tab=activity')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('activity.data', fn ($rows) => ! gitActivityMentionsGit($rows)));
});
