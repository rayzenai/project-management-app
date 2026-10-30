<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * The program page: one row per active project, then the progress report
 * across all of them. Organization O: office admin; program P managed by the
 * joint secretary; projects A and B in P, each with its own manager and an
 * officer.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create();

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->js = User::factory()->create();
    $this->jsMember = Member::factory()->linkedTo($this->js)->create(['organization_id' => $this->org->id, 'name' => 'Joint Secretary']);
    $this->program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jsMember->id, 'name' => 'Revenue']);

    $this->projectA = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal']);
    $this->projectB = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'Customs']);

    $this->managerUserA = User::factory()->create();
    $this->managerA = Member::factory()->linkedTo($this->managerUserA)->create(['organization_id' => $this->org->id, 'name' => 'Manager A']);
    $this->officer = Member::factory()->create(['organization_id' => $this->org->id, 'name' => 'Officer']);
    $this->projectA->ensureTeam()->members()->attach([$this->managerA->id => ['role' => 'leader'], $this->officer->id => ['role' => 'member']]);

    $this->managerB = Member::factory()->create(['organization_id' => $this->org->id, 'name' => 'Manager B']);
    $this->projectB->ensureTeam()->members()->attach([$this->managerB->id => ['role' => 'leader'], $this->officer->id => ['role' => 'member']]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function programProgressTask(Project $project, array $attributes, array $assignees = []): Task
{
    $task = Task::factory()->create(['project_id' => $project->id] + $attributes);

    foreach ($assignees as $member) {
        ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id, 'personal_progress' => 40]);
    }

    return $task;
}

it('shows a program manager every project and everyone working on them', function () {
    programProgressTask($this->projectA, ['status' => 'in_progress', 'deadline_at' => '2026-10-20'], [$this->officer]);
    programProgressTask($this->projectA, ['status' => 'done', 'deadline_at' => '2026-10-10', 'completed_at' => '2026-10-09'], [$this->officer]);
    programProgressTask($this->projectB, ['status' => 'not_started', 'deadline_at' => '2026-11-15'], [$this->managerB]);

    $this->actingAs($this->js)->get("/workspace/programs/{$this->program->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Programs/Show')
            ->where('progress.program.name', 'Revenue')
            ->where('progress.program.manager.name', 'Joint Secretary')
            ->has('progress.projects', 2)
            ->where('progress.projects.0.title', 'Customs')
            ->where('progress.projects.0.tasks_count', 1)
            ->where('progress.projects.0.done_tasks_count', 0)
            ->where('progress.projects.0.overdue_tasks_count', 0)
            ->where('progress.projects.0.manager.name', 'Manager B')
            ->where('progress.projects.0.next_deadline_at', fn ($d) => str_starts_with((string) $d, '2026-11-15'))
            ->where('progress.projects.1.title', 'Tax Portal')
            ->where('progress.projects.1.tasks_count', 2)
            ->where('progress.projects.1.done_tasks_count', 1)
            ->where('progress.projects.1.overdue_tasks_count', 1)
            ->where('progress.projects.1.next_deadline_at', null)
            ->has('progress.tasks', 3)
            ->where('progress.members', fn ($members) => collect($members)->pluck('name')->sort()->values()->all() === ['Manager A', 'Manager B', 'Officer'])
            ->where('progress.members', fn ($members) => collect($members)->firstWhere('name', 'Officer')['assigned'] === 2));
});

it('leaves archived projects out', function () {
    $this->projectB->forceFill(['archived_at' => now()])->save();
    programProgressTask($this->projectB, ['status' => 'in_progress'], [$this->managerB]);

    $this->actingAs($this->js)->get("/workspace/programs/{$this->program->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('progress.projects', 1)
            ->where('progress.projects.0.title', 'Tax Portal')
            ->has('progress.tasks', 0)
            ->where('progress.members', fn ($members) => collect($members)->pluck('name')->sort()->values()->all() === ['Manager A', 'Officer']));
});

it('lists a person with two hats once, as leader', function () {
    $this->projectA->team()->members()->attach($this->jsMember->id, ['role' => 'member']);
    $projectC = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'Audit']);
    $projectC->ensureTeam()->members()->attach($this->jsMember->id, ['role' => 'leader']);
    programProgressTask($this->projectA, ['status' => 'in_progress'], [$this->jsMember]);

    $this->actingAs($this->js)->get("/workspace/programs/{$this->program->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('progress.members', function ($members) {
                $mine = collect($members)->where('id', $this->jsMember->id);

                return $mine->count() === 1 && $mine->first()['role'] === 'leader';
            }));
});

it('renders an empty program', function () {
    $empty = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jsMember->id, 'name' => 'Empty']);

    $this->actingAs($this->js)->get("/workspace/programs/{$empty->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('progress.projects', 0)
            ->has('progress.tasks', 0)
            ->has('progress.members', 0));
});

it('opens the page to the program manager, office admin and super-admin only', function () {
    $superAdmin = User::factory()->create(['email' => 'boss@example.com']);
    $member = User::factory()->create();
    Member::factory()->linkedTo($member)->create(['organization_id' => $this->org->id]);
    $outsider = User::factory()->create();
    Member::factory()->linkedTo($outsider)->create(['organization_id' => $this->otherOrg->id, 'is_office_admin' => true]);

    $web = "/workspace/programs/{$this->program->slug}";
    $api = "/api/v1/workspace/programs/{$this->program->slug}/progress";

    foreach ([$this->js, $this->adminUser, $superAdmin] as $user) {
        $this->actingAs($user)->get($web)->assertOk();
        Sanctum::actingAs($user);
        $this->getJson($api)->assertOk()->assertJsonPath('data.program.slug', $this->program->slug)->assertJsonCount(2, 'data.projects');
    }

    foreach ([$this->managerUserA, $member, $outsider] as $user) {
        $this->actingAs($user)->get($web)->assertForbidden();
        Sanctum::actingAs($user);
        $this->getJson($api)->assertForbidden();
    }
});
