<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * The add field's project picker opens on "Your projects" and offers a
 * dropdown of the organizations whose projects the person may add to:
 * `quickAddContext.organizations`, the organizations of the projects in
 * `quickAddContext.projects`, each once, by name.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->zeta = Organization::factory()->create(['name' => 'Zeta Ministry']);
    $this->alpha = Organization::factory()->create(['name' => 'Alpha Office']);
    // Has a project nobody here may see, and so is never offered.
    $this->hidden = Organization::factory()->create(['name' => 'Hidden Office']);
    Project::factory()->create(['organization_id' => $this->hidden->id]);

    $this->organizationsFor = function (User $user): array {
        $organizations = null;

        $this->actingAs($user)->get('/workspace/my')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$organizations): void {
                $organizations = $page->toArray()['props']['quickAddContext']['organizations'];
            });

        return $organizations;
    };
});

function qacMemberOf(Organization $organization): array
{
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create(['organization_id' => $organization->id]);

    return [$user, $member];
}

function qacOnTeam(Project $project, Member $member): void
{
    $project->ensureTeam()->members()->attach($member->id, ['role' => 'member']);
}

it('lists the organizations of the projects a super-admin may add to, each once, by name', function () {
    Project::factory()->count(2)->create(['organization_id' => $this->zeta->id]);
    Project::factory()->count(3)->create(['organization_id' => $this->alpha->id]);
    $empty = Organization::factory()->create(['name' => 'Beta Office']);

    $boss = User::factory()->create(['email' => 'boss@example.com']);

    expect(($this->organizationsFor)($boss))->toEqual([
        ['id' => $this->alpha->id, 'name' => 'Alpha Office'],
        ['id' => $this->hidden->id, 'name' => 'Hidden Office'],
        ['id' => $this->zeta->id, 'name' => 'Zeta Ministry'],
    ])->and(collect(($this->organizationsFor)($boss))->pluck('id'))->not->toContain($empty->id);
});

it('gives a member of one organization that organization only', function () {
    [$user, $member] = qacMemberOf($this->zeta);
    $mine = Project::factory()->create(['organization_id' => $this->zeta->id]);
    qacOnTeam($mine, $member);
    Project::factory()->public()->create(['organization_id' => $this->zeta->id]);
    // Another organization's public project is not theirs to see.
    Project::factory()->public()->create(['organization_id' => $this->alpha->id]);

    expect(($this->organizationsFor)($user))->toEqual([
        ['id' => $this->zeta->id, 'name' => 'Zeta Ministry'],
    ]);
});

it('gives a member on a cross-organization team both organizations', function () {
    [$user, $member] = qacMemberOf($this->zeta);
    qacOnTeam(Project::factory()->create(['organization_id' => $this->zeta->id]), $member);

    $joint = Project::factory()->create(['organization_id' => $this->alpha->id]);
    $joint->ensureTeam()->update(['is_cross_organization' => true]);
    qacOnTeam($joint, $member);

    expect(($this->organizationsFor)($user))->toEqual([
        ['id' => $this->alpha->id, 'name' => 'Alpha Office'],
        ['id' => $this->zeta->id, 'name' => 'Zeta Ministry'],
    ]);
});

it('is empty for someone with no project to add to', function () {
    [$user] = qacMemberOf($this->zeta);

    expect(($this->organizationsFor)($user))->toBe([]);
});

it('lists the organizations without a query per project', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $count = function () use ($boss): int {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        $this->actingAs($boss)->get('/workspace/guide')->assertOk();

        return $queries;
    };

    // Each project has its team, as every project does.
    Project::factory()->create(['organization_id' => $this->zeta->id])->ensureTeam();
    $this->hidden->projects()->sole()->ensureTeam();
    // The first visit makes the super-admin's member and fills caches.
    $count();
    $before = $count();

    foreach ([$this->zeta, $this->alpha, $this->alpha, Organization::factory()->create()] as $organization) {
        Project::factory()->create(['organization_id' => $organization->id])->ensureTeam();
    }

    expect($count())->toBe($before)
        ->and(($this->organizationsFor)($boss))->toHaveCount(4);
});
