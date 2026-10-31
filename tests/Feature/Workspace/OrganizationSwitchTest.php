<?php

use App\Models\AttendanceSetting;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;
use App\Support\WorkspaceOrganization;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

/*
 * A super-admin's one organization switch: the sidebar's projects and the
 * organization the office pages open on, set from the sidebar or by picking
 * one on a page. Everyone else's workspace is their own organization's.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->sdoc = Organization::factory()->create(['name' => 'SDOC']);
    $this->mof = Organization::factory()->create(['name' => 'MoF']);
    $this->opmcm = Organization::factory()->create(['name' => 'OPMCM']);
    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $this->admin = Member::factory()->withUser()->create(['organization_id' => $this->sdoc->id, 'is_office_admin' => true]);
    Member::factory()->create(['organization_id' => $this->mof->id, 'name' => 'Mina MoF']);
});

/** @return array<string, mixed> the page's props */
function switchProps(TestResponse $response): array
{
    return $response->assertOk()->viewData('page')['props'];
}

it('offers a super-admin the switch, starting on all organizations, and nobody else', function () {
    expect(switchProps($this->actingAs($this->boss)->get('/workspace'))['organizationSwitch'])->toBe([
        'organizations' => [
            ['id' => $this->mof->id, 'name' => 'MoF'],
            ['id' => $this->opmcm->id, 'name' => 'OPMCM'],
            ['id' => $this->sdoc->id, 'name' => 'SDOC'],
        ],
        'current' => null,
    ])->and(switchProps($this->actingAs($this->admin->user)->get('/workspace'))['organizationSwitch'])->toBeNull();
});

it('switches, then opens the page it was used on again without the organization it named', function () {
    $this->actingAs($this->boss)
        ->from("/workspace/attendance?organization={$this->sdoc->id}&under=4&date=2026-10-01")
        ->put('/workspace/organization', ['organization' => $this->mof->id])
        ->assertRedirect('/workspace/attendance?date=2026-10-01');

    expect($this->boss->refresh()->workspace_organization_id)->toBe($this->mof->id)
        ->and(switchProps($this->actingAs($this->boss)->get('/workspace'))['organizationSwitch']['current'])->toBe($this->mof->id);

    $this->actingAs($this->boss)->from('/workspace/team')->put('/workspace/organization', ['organization' => 'all'])->assertRedirect('/workspace/team');

    expect($this->boss->refresh()->workspace_organization_id)->toBeNull();
});

it('sends the switch back only to a workspace page', function () {
    $this->actingAs($this->boss)->from('https://elsewhere.example/steal')
        ->put('/workspace/organization', ['organization' => $this->mof->id])
        ->assertRedirect(route('workspace.home'));
});

it('lets only a super-admin switch, and only to an organization that exists', function () {
    $this->actingAs($this->admin->user)->put('/workspace/organization', ['organization' => $this->mof->id])->assertForbidden();
    $this->actingAs($this->boss)->put('/workspace/organization', ['organization' => 999999])->assertSessionHasErrors('organization');
    $this->actingAs($this->boss)->put('/workspace/organization', ['organization' => 'everything'])->assertSessionHasErrors('organization');

    expect($this->admin->user->refresh()->workspace_organization_id)->toBeNull()
        ->and($this->boss->refresh()->workspace_organization_id)->toBeNull();
});

it('opens the office pages on the switched organization; a page\'s own pick wins and switches', function () {
    WorkspaceOrganization::choose($this->boss, $this->mof->id);

    $this->actingAs($this->boss)->get('/workspace/organogram')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organogram.organization.id', $this->mof->id));
    $this->actingAs($this->boss)->get('/workspace/maintenance')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('maintenance.organization.id', $this->mof->id));

    $this->actingAs($this->boss)->get("/workspace/organogram?organization={$this->opmcm->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organogram.organization.id', $this->opmcm->id));

    expect($this->boss->refresh()->workspace_organization_id)->toBe($this->opmcm->id);
});

it("opens a page that doesn't offer the switched organization on its own first", function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->sdoc->id, 'enabled_at' => '2026-10-01 09:00:00']);
    WorkspaceOrganization::choose($this->boss, $this->mof->id);

    $register = switchProps($this->actingAs($this->boss)->get('/workspace/attendance'));

    // MoF has no attendance: the register opens on SDOC, and the switch stays.
    expect($register['filters']['organization'])->toBe($this->sdoc->id)
        ->and($this->boss->refresh()->workspace_organization_id)->toBe($this->mof->id);
});

it('shows People of the switched organization, and all of them once asked for', function () {
    WorkspaceOrganization::choose($this->boss, $this->mof->id);

    $people = switchProps($this->actingAs($this->boss)->get('/workspace/team'));
    expect($people['organizationFilter'])->toBe($this->mof->id)
        ->and(array_column($people['members'], 'name'))->toBe(['Mina MoF']);

    $everyone = switchProps($this->actingAs($this->boss)->get('/workspace/team?organization=all'));
    expect($everyone['organizationFilter'])->toBeNull()
        ->and(array_column($everyone['members'], 'name'))->toContain('Mina MoF', $this->admin->name)
        ->and($this->boss->refresh()->workspace_organization_id)->toBeNull();
});

it('ignores a pick and a stored choice for anyone but a super-admin', function () {
    $user = $this->admin->user;
    $user->forceFill(['workspace_organization_id' => $this->mof->id])->save();

    $this->actingAs($user)->get('/workspace/organogram')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('organogram.organization.id', $this->sdoc->id));

    $user->forceFill(['workspace_organization_id' => null])->save();
    $this->actingAs($user)->get("/workspace/organogram?organization={$this->mof->id}")->assertOk();

    expect($user->refresh()->workspace_organization_id)->toBeNull();
});

it('remembers only an organization that exists, and switches back to all when it is deleted', function () {
    $this->actingAs($this->boss)->get('/workspace/organogram?organization=999999');
    expect($this->boss->refresh()->workspace_organization_id)->toBeNull();

    WorkspaceOrganization::choose($this->boss, $this->opmcm->id);
    $this->opmcm->delete();

    expect($this->boss->refresh()->workspace_organization_id)->toBeNull();
});

it('writes the choice only when it changes', function () {
    WorkspaceOrganization::choose($this->boss, $this->mof->id);
    $stamp = $this->boss->refresh()->updated_at;
    $this->travel(5)->minutes();

    $this->actingAs($this->boss)->get("/workspace/organogram?organization={$this->mof->id}")->assertOk();

    expect($this->boss->refresh()->updated_at->equalTo($stamp))->toBeTrue();
});

it('keeps Home, Projects, My Workspace and the activity feed to the switched organization, and the app to everything', function () {
    $me = Member::forUser($this->boss);
    $projects = [];
    foreach ([$this->sdoc, $this->mof] as $organization) {
        $project = Project::factory()->create(['organization_id' => $organization->id, 'title' => "{$organization->name} work"]);
        $task = Task::factory()->for($project)->create(['status' => 'not_started', 'title' => "Task of {$organization->name}"]);
        ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $me->id]);
        $projects[] = $project;
    }
    $projects[0]->forceFill(['archived_at' => now()])->save();
    Project::factory()->create(['organization_id' => $this->sdoc->id, 'title' => 'Archived SDOC', 'archived_at' => now()]);
    $titles = fn (array $tasks): array => array_column($tasks, 'title');

    // Everything while switched to all, as before.
    $everything = switchProps($this->actingAs($this->boss)->get('/workspace/projects?archived=1'));
    expect($everything['archivedCount'])->toBe(2);

    $projects[0]->forceFill(['archived_at' => null])->save();
    WorkspaceOrganization::choose($this->boss, $this->mof->id);

    $home = switchProps($this->actingAs($this->boss)->get('/workspace'));
    expect(array_column($home['projects'], 'title'))->toBe(['MoF work'])
        ->and(collect($home['buckets'])->flatMap(fn (array $bucket) => $titles($bucket['tasks']))->all())->toBe(['Task of MoF'])
        ->and($home['stats']['open'])->toBe(1);

    $list = switchProps($this->actingAs($this->boss)->get('/workspace/projects'));
    expect(array_column($list['projects'], 'title'))->toBe(['MoF work'])
        ->and($list['archivedCount'])->toBe(0);

    $mine = switchProps($this->actingAs($this->boss)->get('/workspace/my'));
    expect(collect($mine['assignments'])->pluck('task.title')->all())->toBe(['Task of MoF'])
        ->and(array_column($mine['projects'], 'title'))->toBe(['MoF work']);

    $feed = switchProps($this->actingAs($this->boss)->get('/workspace/notifications?tab=activity'));
    expect(collect($feed['activity']['data'])->pluck('project_title')->unique()->values()->all())->toBe(['MoF work']);

    // The app has no switch: its Home keeps every organization.
    $app = $this->actingAs($this->boss, 'sanctum')->getJson('/api/v1/workspace/home')->assertOk()->json();
    expect(array_column($app['projects'], 'title'))->toBe(['MoF work', 'SDOC work']);
});
