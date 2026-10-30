<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionedInComment;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * Programs across the workspace: the shared context, the Projects page and
 * mentions. Organization O: an office admin; programs Revenue (managed by
 * the joint secretary) and Customs (managed by someone else); project A in
 * Revenue with its own manager and an officer, project B in no program.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create();

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->js = User::factory()->create();
    $this->jsMember = Member::factory()->linkedTo($this->js)->create(['organization_id' => $this->org->id, 'name' => 'Joint Secretary']);
    $this->revenue = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jsMember->id, 'name' => 'Revenue']);
    $this->customs = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => Member::factory()->create(['organization_id' => $this->org->id])->id, 'name' => 'Customs']);
    Program::factory()->create(['organization_id' => $this->otherOrg->id, 'manager_id' => Member::factory()->create(['organization_id' => $this->otherOrg->id])->id, 'name' => 'Elsewhere']);

    $this->projectA = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->revenue->id, 'title' => 'Tax Portal']);
    $this->projectB = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Library']);

    $this->managerUser = User::factory()->create();
    $this->manager = Member::factory()->linkedTo($this->managerUser)->create(['organization_id' => $this->org->id, 'name' => 'Under Secretary']);
    $this->officerUser = User::factory()->create();
    $this->officer = Member::factory()->linkedTo($this->officerUser)->create(['organization_id' => $this->org->id, 'name' => 'Officer']);
    $this->projectA->ensureTeam()->members()->attach([$this->manager->id => ['role' => 'leader'], $this->officer->id => ['role' => 'member']]);
    $this->projectB->ensureTeam()->members()->attach([$this->manager->id => ['role' => 'leader'], $this->officer->id => ['role' => 'member']]);
});

it('shares the programs a person manages', function () {
    $second = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->jsMember->id, 'name' => 'Audit']);

    $this->actingAs($this->js)->get('/workspace/team')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('managedProgramIds', [$this->revenue->id, $second->id])
            ->where('managedPrograms', [
                ['slug' => $second->slug, 'name' => 'Audit'],
                ['slug' => $this->revenue->slug, 'name' => 'Revenue'],
            ]));

    $this->actingAs($this->officerUser)->get('/workspace/my')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('managedProgramIds', [])
            ->where('managedPrograms', []));

    Sanctum::actingAs($this->js);
    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('managed_program_ids', [$this->revenue->id, $second->id]);

    Sanctum::actingAs($this->officerUser);
    $this->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('managed_program_ids', []);
});

it('shares no programs with a deactivated program manager: they are signed out', function () {
    $this->jsMember->update(['is_active' => false]);

    $this->actingAs($this->js)->get('/workspace/my')->assertRedirect('/workspace/login');

    $this->assertGuest();
});

it('gives the projects page its programs and each project its program', function () {
    $this->actingAs($this->adminUser)->get('/workspace/projects')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Projects/Index')
            ->where('canManagePrograms', true)
            ->has('programs', 2)
            ->where('programs.0.name', 'Customs')
            ->where('programs.0.projects_count', 0)
            ->where('programs.1.name', 'Revenue')
            ->where('programs.1.slug', $this->revenue->slug)
            ->where('programs.1.manager.name', 'Joint Secretary')
            ->where('programs.1.projects_count', 1)
            ->where('projects', fn ($projects) => collect($projects)->firstWhere('title', 'Tax Portal')['program'] === ['slug' => $this->revenue->slug, 'name' => 'Revenue']
                && collect($projects)->firstWhere('title', 'Library')['program'] === null));

    // The program manager sees the program they manage, and runs no programs.
    $this->actingAs($this->js)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManagePrograms', false)
            ->has('programs', 1)
            ->where('programs.0.name', 'Revenue'));

    // A project manager or officer may open none, but each of their rows
    // still names its program, which the page groups by.
    $this->actingAs($this->officerUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManagePrograms', false)
            ->where('programs', [])
            ->where('projects', fn ($projects) => collect($projects)->firstWhere('title', 'Tax Portal')['program'] === ['slug' => $this->revenue->slug, 'name' => 'Revenue']));

    // A super-admin sees every organization's programs.
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('canManagePrograms', true)
            ->has('programs', 3));

    Sanctum::actingAs($this->adminUser);
    $this->getJson('/api/v1/workspace/projects')
        ->assertOk()
        ->assertJsonPath('canManagePrograms', true)
        ->assertJsonCount(2, 'programs');
});

it('gives an office admin the programs a project can move to on its page', function () {
    $this->actingAs($this->adminUser)->get("/workspace/projects/{$this->projectB->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('programs', [
                ['id' => $this->customs->id, 'slug' => $this->customs->slug, 'name' => 'Customs'],
                ['id' => $this->revenue->id, 'slug' => $this->revenue->slug, 'name' => 'Revenue'],
            ]));

    // Its manager and its program's manager cannot move it, so they get none.
    $this->actingAs($this->managerUser)->get("/workspace/projects/{$this->projectA->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('programs', []));
    $this->actingAs($this->js)->get("/workspace/projects/{$this->projectA->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('programs', []));
});

it('offers the program manager for mentions on a program task and notifies them', function () {
    Notification::fake();
    $task = Task::factory()->create(['project_id' => $this->projectA->id, 'title' => 'Draft the rules']);

    $this->actingAs($this->managerUser)->get("/workspace/projects/{$this->projectA->slug}/tasks/{$task->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mentionCandidates', fn ($people) => collect($people)->pluck('name')->sort()->values()->all() === ['Joint Secretary', 'Officer', 'Under Secretary'])
            // The comment box needs a name to insert, nothing more: no email
            // or login of someone the teammate may not otherwise see.
            ->where('mentionCandidates', fn ($people) => collect($people)->firstWhere('id', $this->jsMember->id) === ['id' => $this->jsMember->id, 'name' => 'Joint Secretary']
                && collect($people)->every(fn (array $person): bool => array_keys($person) === ['id', 'name']))
            // Who can be given the task is unchanged: the program manager is not on its team.
            ->where('team', fn ($people) => ! collect($people)->pluck('id')->contains($this->jsMember->id)));

    $this->actingAs($this->managerUser)
        ->post("/workspace/tasks/{$task->id}/comments", ['body' => "Please review @[Joint Secretary](member:{$this->jsMember->id})"])
        ->assertRedirect();

    Notification::assertSentTo($this->js, MentionedInComment::class);
});

it('offers the program manager once when they are also on the team', function () {
    $this->projectA->team()->members()->attach($this->jsMember->id, ['role' => 'member']);
    $task = Task::factory()->create(['project_id' => $this->projectA->id]);

    $this->actingAs($this->managerUser)->get("/workspace/projects/{$this->projectA->slug}/tasks/{$task->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mentionCandidates', fn ($people) => collect($people)->where('id', $this->jsMember->id)->count() === 1));
});

it('offers no program manager outside a program, or one who is deactivated', function () {
    $outside = Task::factory()->create(['project_id' => $this->projectB->id]);

    $this->actingAs($this->managerUser)->get("/workspace/projects/{$this->projectB->slug}/tasks/{$outside->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mentionCandidates', fn ($people) => ! collect($people)->pluck('id')->contains($this->jsMember->id)));

    $this->jsMember->update(['is_active' => false]);
    $inside = Task::factory()->create(['project_id' => $this->projectA->id]);

    $this->actingAs($this->managerUser)->get("/workspace/projects/{$this->projectA->slug}/tasks/{$inside->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('mentionCandidates', fn ($people) => ! collect($people)->pluck('id')->contains($this->jsMember->id)));
});
