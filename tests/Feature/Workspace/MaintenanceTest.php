<?php

use App\Models\AttendanceNetwork;
use App\Models\AttendanceSetting;
use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;
use App\Queries\Maintenance\MaintenanceQuery;
use Inertia\Testing\AssertableInertia;

/*
 * The maintenance list: what needs looking at in an organization, for
 * whoever administers it. One organization holds exactly one case of each
 * check, next to one that is fine.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'SDOC']);
    $this->otherOrg = Organization::factory()->create(['name' => 'MoF']);
    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
    $title = Designation::factory()->create(['organization_id' => $this->org->id, 'name' => 'Engineer']);
    $person = fn (string $name, array $attributes = []) => Member::factory()->withUser()->create([
        'organization_id' => $this->org->id, 'name' => $name, 'designation_id' => $title->id, ...$attributes,
    ]);

    $this->admin = $person('Asha Admin', ['is_office_admin' => true]);
    $this->ed = $person('Kiran ED');
    $this->manager = $person('Mira Manager', ['reports_to_id' => $this->ed->id]);
    $this->gone = $person('Gopal Gone', ['is_active' => false, 'reports_to_id' => $this->ed->id]);
    $this->stranded = $person('Sita Stranded', ['reports_to_id' => $this->gone->id]);
    $this->untitled = $person('Uma Untitled', ['designation_id' => null, 'reports_to_id' => $this->ed->id]);
    $this->loose = $person('Lal Loose');
    $this->noLogin = Member::factory()->create(['organization_id' => $this->org->id, 'name' => 'Nabin Nologin', 'designation_id' => $title->id, 'reports_to_id' => $this->ed->id]);
    $this->admin->update(['reports_to_id' => $this->ed->id]);

    // Fine: an active manager, the team busy, every open task held.
    $this->fine = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Fine']);
    $this->fine->ensureTeam()->members()->attach([
        $this->manager->id => ['role' => 'leader'],
        $this->ed->id => ['role' => 'member'],
        $this->admin->id => ['role' => 'member'],
        $this->untitled->id => ['role' => 'member'],
        $this->stranded->id => ['role' => 'member'],
        $this->noLogin->id => ['role' => 'member'],
    ]);
    $held = Task::factory()->create(['project_id' => $this->fine->id, 'status' => 'in_progress']);
    ProjectAssignment::create(['task_id' => $held->id, 'member_id' => $this->manager->id]);
    Task::factory()->create(['project_id' => $this->fine->id, 'status' => 'done']);

    // No manager, nobody on the team, and an open task nobody holds.
    $this->empty = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Empty']);
    $this->empty->ensureTeam();
    Task::factory()->create(['project_id' => $this->empty->id, 'status' => 'not_started']);

    // Its manager left, and so did the only one holding its open task.
    $this->orphaned = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Orphaned']);
    $this->orphaned->ensureTeam()->members()->attach([$this->gone->id => ['role' => 'leader'], $this->manager->id => ['role' => 'member']]);
    $left = Task::factory()->create(['project_id' => $this->orphaned->id, 'status' => 'in_progress']);
    ProjectAssignment::create(['task_id' => $left->id, 'member_id' => $this->gone->id]);

    // Nothing has happened on it for 40 days.
    $this->quiet = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Quiet']);
    $this->quiet->ensureTeam()->members()->attach($this->manager->id, ['role' => 'leader']);

    // Archived: never listed, whatever is wrong with it.
    Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Archived', 'archived_at' => now()]);

    $this->program = Program::factory()->create(['organization_id' => $this->org->id, 'name' => 'Revenue', 'manager_id' => $this->gone->id]);
    Program::factory()->create(['organization_id' => $this->org->id, 'name' => 'Customs', 'manager_id' => $this->manager->id]);

    ProjectActivity::query()->where('project_id', $this->quiet->id)->delete();
    $this->quiet->forceFill(['created_at' => now()->subDays(40)])->save();

    AttendanceSetting::factory()->create(['organization_id' => $this->org->id]);
});

it('lists exactly what needs looking at', function () {
    $data = app(MaintenanceQuery::class)->get($this->org->id);
    $slugs = fn (string $check): array => array_column($data[$check], 'slug');
    $names = fn (string $check): array => array_column($data[$check], 'name');

    expect($data['organization'])->toBe(['id' => $this->org->id, 'name' => 'SDOC'])
        ->and($data['quiet_days'])->toBe(MaintenanceQuery::QUIET_DAYS)
        ->and($data['projects_without_manager'])->toEqual([
            ['slug' => $this->empty->slug, 'label' => 'Empty', 'manager' => null],
            ['slug' => $this->orphaned->slug, 'label' => 'Orphaned', 'manager' => ['id' => $this->gone->id, 'name' => 'Gopal Gone', 'designation' => 'Engineer']],
        ])
        ->and($slugs('projects_without_people'))->toBe([$this->empty->slug])
        ->and($data['unassigned_tasks'])->toEqual([['slug' => $this->empty->slug, 'label' => 'Empty', 'count' => 1]])
        ->and($data['tasks_with_inactive_people'])->toEqual([['slug' => $this->orphaned->slug, 'label' => 'Orphaned', 'count' => 1]])
        ->and($slugs('quiet_projects'))->toBe([$this->quiet->slug])
        ->and($data['programs_without_manager'])->toEqual([
            ['slug' => $this->program->slug, 'name' => 'Revenue', 'manager' => ['id' => $this->gone->id, 'name' => 'Gopal Gone', 'designation' => 'Engineer']],
        ])
        ->and($names('people_off_projects'))->toBe(['Lal Loose'])
        ->and($names('people_without_designation'))->toBe(['Uma Untitled'])
        ->and($data['people_reporting_to_inactive'])->toEqual([
            ['id' => $this->stranded->id, 'name' => 'Sita Stranded', 'designation' => 'Engineer', 'reports_to' => ['id' => $this->gone->id, 'name' => 'Gopal Gone', 'designation' => 'Engineer']],
        ])
        ->and($names('people_outside_organogram'))->toBe(['Lal Loose'])
        ->and($names('people_without_login'))->toBe(['Nabin Nologin'])
        ->and($data['attendance_without_office'])->toBeTrue();
});

it('clears each check once it is fixed', function () {
    $this->empty->team()->members()->attach($this->manager->id, ['role' => 'leader']);
    $this->orphaned->team()->members()->detach($this->gone->id);
    $this->orphaned->team()->members()->updateExistingPivot($this->manager->id, ['role' => 'leader']);
    ProjectAssignment::query()->update(['member_id' => $this->manager->id]);
    ProjectAssignment::create(['task_id' => Task::query()->where('project_id', $this->empty->id)->value('id'), 'member_id' => $this->manager->id]);
    $this->quiet->forceFill(['archived_at' => now()])->save();
    $this->program->update(['manager_id' => $this->manager->id]);
    $this->loose->update(['reports_to_id' => $this->ed->id]);
    $this->fine->team()->members()->attach($this->loose->id, ['role' => 'member']);
    $this->untitled->update(['designation_id' => $this->ed->designation_id]);
    $this->stranded->update(['reports_to_id' => $this->ed->id]);
    $this->noLogin->delete();
    AttendanceNetwork::factory()->create(['organization_id' => $this->org->id]);

    $data = app(MaintenanceQuery::class)->get($this->org->id);

    foreach ($data as $check => $value) {
        if (! in_array($check, ['organization', 'quiet_days'], true)) {
            expect($value)->toBeEmpty("{$check} is not empty: ".json_encode($value));
        }
    }
});

it('shows the page to whoever administers the organization, and nobody else', function () {
    $this->actingAs($this->admin->user)
        ->get('/workspace/maintenance')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Maintenance/Index')
            ->where('maintenance.organization.id', $this->org->id)
            ->where('organizations', []));

    // Another organization picked by anyone but a super-admin is ignored.
    $this->actingAs($this->admin->user)
        ->get("/workspace/maintenance?organization={$this->otherOrg->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('maintenance.organization.id', $this->org->id));

    $this->actingAs($this->manager->user)->get('/workspace/maintenance')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/workspace/maintenance')->assertForbidden();

    $this->actingAs($this->boss)
        ->get("/workspace/maintenance?organization={$this->org->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('maintenance.organization.id', $this->org->id)
            ->has('organizations', 2));
});

it('answers the API for the organization in the path, to its administrators only', function () {
    $otherAdmin = Member::factory()->withUser()->create(['organization_id' => $this->otherOrg->id, 'is_office_admin' => true]);

    $this->actingAs($this->admin->user, 'sanctum')
        ->getJson("/api/v1/workspace/organizations/{$this->org->id}/maintenance")
        ->assertOk()
        ->assertJsonPath('data.organization.name', 'SDOC')
        ->assertJsonPath('data.projects_without_people.0.label', 'Empty');

    $this->actingAs($otherAdmin->user, 'sanctum')
        ->getJson("/api/v1/workspace/organizations/{$this->org->id}/maintenance")
        ->assertForbidden();
    $this->actingAs($this->manager->user, 'sanctum')
        ->getJson("/api/v1/workspace/organizations/{$this->org->id}/maintenance")
        ->assertForbidden();
});

it('offers the sidebar link to administrators only', function () {
    $this->actingAs($this->admin->user, 'sanctum')->getJson('/api/v1/user')->assertJsonPath('office.maintenance', true);
    $this->actingAs($this->manager->user, 'sanctum')->getJson('/api/v1/user')->assertJsonPath('office.maintenance', false);
});
