<?php

use App\Models\GitConnection;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectKeyAlias;
use App\Models\Task;
use App\Models\User;

/*
 * The forge's link back: a linked repository's external tracker format is
 * {APP_URL}/workspace/go/{user}/{index}, where Forgejo fills {user} with the
 * repository's owner (the forge organization) and {index} with the whole
 * alphanumeric reference (TP-1).
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    GitConnection::query()->create([
        'organization_id' => $this->organization->id,
        'base_url' => 'https://git.example',
        'owner' => 'Sdoc',
        'token' => 'bot-token',
    ]);
    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Tax Portal', 'key' => 'TP']);
    $this->task = Task::factory()->for($this->project)->create();
});

/** Someone on the project's team. */
function goToViewer(Project $project): User
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $project->organization_id]);
    $project->ensureTeam()->members()->attach($member->id);

    return $user;
}

function goToTaskUrl(Task $task): string
{
    return route('workspace.tasks.show', ['project' => $task->project->slug, 'task' => $task->slug]);
}

it('sends someone who can see the task to it', function () {
    expect($this->task->number)->toBe(1);

    $this->actingAs(goToViewer($this->project))
        ->get('/workspace/go/Sdoc/TP-1')
        ->assertRedirect(goToTaskUrl($this->task));
});

it('matches the forge organization in any case', function (string $org) {
    $this->actingAs(goToViewer($this->project))
        ->get("/workspace/go/{$org}/TP-1")
        ->assertRedirect(goToTaskUrl($this->task));
})->with(['sdoc', 'SDOC']);

it('answers 404, never 403, to someone who can\'t see the task', function (string $who) {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create([
        'organization_id' => $who === 'another office' ? Organization::factory()->create()->id : $this->organization->id,
    ]);

    $this->actingAs($user)->get('/workspace/go/sdoc/TP-1')->assertNotFound();
})->with(['another office', 'a colleague off the team']);

it('answers 404 for an unknown key, number or forge organization, or a code that isn\'t one', function (string $path) {
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))
        ->get($path)
        ->assertNotFound();
})->with([
    'unknown key' => ['/workspace/go/sdoc/ZZ-1'],
    'unknown number' => ['/workspace/go/sdoc/TP-99'],
    'number zero' => ['/workspace/go/sdoc/TP-0'],
    'no dash' => ['/workspace/go/sdoc/TP1'],
    'key too long' => ['/workspace/go/sdoc/ABCDEFGHIJK-1'],
    'unknown organization' => ['/workspace/go/moha/TP-1'],
]);

it('follows an old key to the project', function () {
    $this->project->forceFill(['key' => 'TX'])->save();
    ProjectKeyAlias::query()->create(['project_id' => $this->project->id, 'organization_id' => $this->organization->id, 'key' => 'TP']);

    $this->actingAs(goToViewer($this->project))
        ->get('/workspace/go/sdoc/TP-1')
        ->assertRedirect(goToTaskUrl($this->task));
});

it('resolves the key within the forge organization\'s office only', function () {
    $moha = Organization::factory()->create(['name' => 'MOHA', 'slug' => 'moha']);
    $theirs = Task::factory()->for(Project::factory()->create(['organization_id' => $moha->id, 'key' => 'TP']))->create();
    $unconnected = Organization::factory()->create(['name' => 'MOF', 'slug' => 'mof']);
    Task::factory()->for(Project::factory()->create(['organization_id' => $unconnected->id, 'key' => 'MF']))->create();
    GitConnection::query()->create(['organization_id' => $moha->id, 'base_url' => 'https://git.example', 'owner' => 'moha', 'token' => 'other-token']);
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']));

    $this->get('/workspace/go/moha/TP-1')->assertRedirect(goToTaskUrl($theirs));
    $this->get('/workspace/go/sdoc/TP-1')->assertRedirect(goToTaskUrl($this->task));
    $this->get('/workspace/go/mof/MF-1')->assertNotFound();
    $this->get('/workspace/go/sdoc/MF-1')->assertNotFound();
});

it('doesn\'t open a trashed task', function () {
    $this->task->delete();

    $this->actingAs(goToViewer($this->project))
        ->get('/workspace/go/sdoc/TP-1')
        ->assertNotFound();
});

it('sends a guest to sign in, then on to the task', function () {
    $user = goToViewer($this->project);

    $this->get('/workspace/go/sdoc/TP-1')->assertRedirect(route('workspace.login'));

    $this->post('/workspace/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/workspace/go/sdoc/TP-1');
    $this->get('/workspace/go/sdoc/TP-1')->assertRedirect(goToTaskUrl($this->task));
});

it('leaves the login page with a full page visit on the way to a go link, so a 404 is a page of its own', function () {
    $outsider = User::factory()->create();
    Member::factory()->linkedTo($outsider)->create(['organization_id' => Organization::factory()->create()->id]);

    $this->get('/workspace/go/sdoc/TP-1')->assertRedirect(route('workspace.login'));

    $this->withHeader('X-Inertia', 'true')
        ->post('/workspace/login', ['email' => $outsider->email, 'password' => 'password'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', url('/workspace/go/sdoc/TP-1'));
});

it('keeps the in-app visit for any other page signed in on the way to', function () {
    $user = goToViewer($this->project);

    $this->withSession(['url.intended' => url('/workspace/gone-fishing')])
        ->withHeader('X-Inertia', 'true')
        ->post('/workspace/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(url('/workspace/gone-fishing'));
});
