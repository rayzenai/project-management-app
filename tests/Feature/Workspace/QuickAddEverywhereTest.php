<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AddedToProjectTeam;
use App\Notifications\TaskAssigned;
use App\Support\WorkspaceAccess;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * Adding a task never fails silently; an organization with no project starts
 * one, "General", with its first task; and whoever runs a project's team
 * assigns anyone active in its organization from the task form, who joins
 * the team as a member and then gets the task.
 *
 * Organization O ("Ministry"): an organizational admin; project P in a
 * program the joint secretary manages, run by the under secretary with an
 * officer on its team; a colleague on no team and a deactivated person.
 * Organization E ("OPMCM") has an organizational admin and a person but no
 * project. X is another organization with a person of its own.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create(['name' => 'Elsewhere']);
    $this->emptyOrg = Organization::factory()->create(['name' => 'OPMCM']);

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->jsUser = User::factory()->create();
    $this->js = Member::factory()->linkedTo($this->jsUser)->create(['organization_id' => $this->org->id, 'title' => 'Joint Secretary']);
    $this->program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->js->id]);

    $this->pmUser = User::factory()->create();
    $this->pm = Member::factory()->linkedTo($this->pmUser)->create(['organization_id' => $this->org->id, 'title' => 'Under Secretary']);
    $this->officerUser = User::factory()->create();
    $this->officer = Member::factory()->linkedTo($this->officerUser)->create(['organization_id' => $this->org->id]);

    $this->project = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'Tax Portal']);
    $this->team = $this->project->ensureTeam();
    $this->team->members()->attach($this->pm->id, ['role' => 'leader']);
    $this->team->members()->attach($this->officer->id, ['role' => 'member']);

    $this->colleagueUser = User::factory()->create();
    $this->colleague = Member::factory()->linkedTo($this->colleagueUser)->create([
        'organization_id' => $this->org->id, 'name' => 'Sita Section Officer', 'title' => 'Section Officer',
    ]);
    $this->retired = Member::factory()->inactive()->create(['organization_id' => $this->org->id, 'name' => 'Retired Person']);
    $this->outsider = Member::factory()->withUser()->create(['organization_id' => $this->otherOrg->id, 'name' => 'Visiting Expert']);

    $this->emptyAdminUser = User::factory()->create();
    $this->emptyAdmin = Member::factory()->linkedTo($this->emptyAdminUser)->create(['organization_id' => $this->emptyOrg->id, 'is_office_admin' => true]);
    $this->emptyPersonUser = User::factory()->create();
    $this->emptyPerson = Member::factory()->linkedTo($this->emptyPersonUser)->create(['organization_id' => $this->emptyOrg->id, 'name' => 'Ram Officer']);
});

function qaeOnTeam(Team $team, Member $member): bool
{
    return $team->members()->whereKey($member->id)->exists();
}

function qaeQuickAdd($test, User $user, Project $project, Member $assignee, string $title)
{
    return $test->actingAs($user)->from('/workspace')->post('/workspace/quick-add', [
        'project_id' => $project->id,
        'title' => $title,
        'assignee_member_ids' => [$assignee->id],
    ]);
}

// ---- General for an organization with no project ----

it('starts General for an organization with no project and files the task there', function () {
    Notification::fake();

    $this->actingAs($this->emptyAdminUser)->post('/workspace/quick-add', [
        'start_general' => true,
        'title' => 'Draft the annual plan',
        'assignee_member_ids' => [$this->emptyPerson->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $general = Project::query()->where('organization_id', $this->emptyOrg->id)->sole();
    $task = Task::query()->where('title', 'Draft the annual plan')->sole();

    expect($general->title)->toBe('General')
        ->and($general->manager()?->id)->toBe($this->emptyAdmin->id)
        ->and($task->project_id)->toBe($general->id)
        ->and($task->assignments()->pluck('member_id')->all())->toBe([$this->emptyPerson->id])
        ->and($general->team()?->members()->whereKey($this->emptyPerson->id)->wherePivot('role', 'member')->exists())->toBeTrue();

    Notification::assertSentTo($this->emptyPersonUser, AddedToProjectTeam::class);
    Notification::assertSentTo($this->emptyPersonUser, TaskAssigned::class);
});

it('gives the new General task to the organizational admin when nobody is picked', function () {
    $this->actingAs($this->emptyAdminUser)->post('/workspace/quick-add', [
        'start_general' => true,
        'title' => 'Mine to start',
    ])->assertRedirect()->assertSessionHasNoErrors();

    $task = Task::query()->where('title', 'Mine to start')->sole();

    expect($task->assignments()->pluck('member_id')->all())->toBe([$this->emptyAdmin->id]);
});

it('starts General through the api too', function () {
    Sanctum::actingAs($this->emptyAdminUser, ['*']);

    $this->postJson('/api/v1/workspace/quick-add', [
        'start_general' => true,
        'title' => 'From the app',
        'assignee_member_ids' => [$this->emptyPerson->id],
    ])->assertCreated();

    $general = Project::query()->where('organization_id', $this->emptyOrg->id)->sole();

    expect($general->manager()?->id)->toBe($this->emptyAdmin->id)
        ->and(qaeOnTeam($general->ensureTeam(), $this->emptyPerson))->toBeTrue();
});

it('rolls General back when the task cannot be filed', function () {
    $this->actingAs($this->emptyAdminUser)->post('/workspace/quick-add', [
        'start_general' => true,
        'title' => 'Not for them',
        'assignee_member_ids' => [$this->outsider->id],
    ])->assertSessionHasErrors();

    expect(Project::query()->where('organization_id', $this->emptyOrg->id)->exists())->toBeFalse()
        ->and(Task::query()->where('title', 'Not for them')->exists())->toBeFalse();
});

it('refuses start_general to a member', function () {
    $this->actingAs($this->emptyPersonUser)->post('/workspace/quick-add', [
        'start_general' => true,
        'title' => 'Sneaky start',
    ])->assertSessionHasErrors(['project_id' => 'Pick a project.']);

    expect(Project::query()->where('organization_id', $this->emptyOrg->id)->exists())->toBeFalse()
        ->and(Task::query()->where('title', 'Sneaky start')->exists())->toBeFalse();
});

it('refuses start_general once the organization has a project', function () {
    $this->actingAs($this->adminUser)->post('/workspace/quick-add', [
        'start_general' => true,
        'title' => 'A second start',
    ])->assertSessionHasErrors(['project_id' => 'Pick a project.']);

    expect(Project::query()->where('organization_id', $this->org->id)->where('title', 'General')->exists())->toBeFalse()
        ->and(Task::query()->where('title', 'A second start')->exists())->toBeFalse();
});

it('refuses start_general to a super-admin, who belongs to no organization', function () {
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))->post('/workspace/quick-add', [
        'start_general' => true,
        'title' => 'From above',
    ])->assertSessionHasErrors(['project_id' => 'Pick a project.']);

    expect(Project::query()->where('title', 'General')->exists())->toBeFalse();
});

it('asks for a project when none is sent', function () {
    $this->actingAs($this->pmUser)->post('/workspace/quick-add', ['title' => 'Floating task'])
        ->assertSessionHasErrors(['project_id' => 'Pick a project.']);

    Sanctum::actingAs($this->pmUser, ['*']);

    $this->postJson('/api/v1/workspace/quick-add', ['title' => 'Floating task'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_id' => 'Pick a project.']);

    expect(Task::query()->where('title', 'Floating task')->exists())->toBeFalse();
});

// ---- Assigning anyone in the organization ----

it('lets a project manager assign someone of their organization from quick add, who joins the team', function () {
    Notification::fake();

    qaeQuickAdd($this, $this->pmUser, $this->project, $this->colleague, 'Collect the forms')
        ->assertRedirect()->assertSessionHasNoErrors();

    $task = Task::query()->where('title', 'Collect the forms')->sole();

    expect($task->assignments()->pluck('member_id')->all())->toBe([$this->colleague->id])
        ->and($this->team->members()->whereKey($this->colleague->id)->wherePivot('role', 'member')->exists())->toBeTrue();

    Notification::assertSentTo($this->colleagueUser, AddedToProjectTeam::class);
    Notification::assertSentTo($this->colleagueUser, TaskAssigned::class);
});

it('assigns teammates and newcomers together without touching the teammates', function () {
    $this->actingAs($this->pmUser)->post('/workspace/quick-add', [
        'project_id' => $this->project->id,
        'title' => 'Two hands',
        'assignee_member_ids' => [$this->officer->id, $this->colleague->id],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $task = Task::query()->where('title', 'Two hands')->sole();

    expect($task->assignments()->pluck('member_id')->sort()->values()->all())
        ->toBe(collect([$this->officer->id, $this->colleague->id])->sort()->values()->all())
        ->and($this->team->members()->count())->toBe(3)
        ->and($this->team->leaders()->pluck('members.id')->all())->toBe([$this->pm->id]);
});

it('lets a project manager assign someone of their organization on the task page, who joins the team', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->pmUser)->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $this->colleague->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($task->assignments()->pluck('member_id')->all())->toBe([$this->colleague->id])
        ->and($this->team->members()->whereKey($this->colleague->id)->wherePivot('role', 'member')->exists())->toBeTrue();
});

it('lets a program manager assign someone of their organization through the api, who joins the team', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    Sanctum::actingAs($this->jsUser, ['*']);

    $this->postJson("/api/v1/workspace/tasks/{$task->id}/assignments", ['member_id' => $this->colleague->id])
        ->assertCreated();

    expect($task->assignments()->pluck('member_id')->all())->toBe([$this->colleague->id])
        ->and(qaeOnTeam($this->team, $this->colleague))->toBeTrue()
        // A program manager's program powers never put them on the team.
        ->and(qaeOnTeam($this->team, $this->js))->toBeFalse();
});

it('does not let a plain member assign someone off the team', function () {
    qaeQuickAdd($this, $this->officerUser, $this->project, $this->colleague, 'Hand it off')
        ->assertRedirect('/workspace')->assertSessionHasErrors();

    $task = Task::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->officerUser)->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $this->colleague->id])
        ->assertSessionHasErrors('member_id');

    expect(Task::query()->where('title', 'Hand it off')->exists())->toBeFalse()
        ->and($task->assignments()->count())->toBe(0)
        ->and(qaeOnTeam($this->team, $this->colleague))->toBeFalse();
});

it('lets nobody assign someone of another organization or a deactivated person', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $superAdmin = User::factory()->create(['email' => 'boss@example.com']);

    foreach ([$superAdmin, $this->adminUser, $this->jsUser, $this->pmUser] as $viewer) {
        foreach ([$this->outsider, $this->retired] as $person) {
            qaeQuickAdd($this, $viewer, $this->project, $person, 'Out of bounds')
                ->assertRedirect('/workspace')->assertSessionHasErrors();

            $this->actingAs($viewer)->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $person->id])
                ->assertSessionHasErrors('member_id');
        }
    }

    expect(Task::query()->where('title', 'Out of bounds')->exists())->toBeFalse()
        ->and($task->assignments()->count())->toBe(0)
        ->and(qaeOnTeam($this->team, $this->outsider))->toBeFalse()
        ->and(qaeOnTeam($this->team, $this->retired))->toBeFalse();
});

it('never brings in an outsider through a cross-organization team, for its manager or its program manager', function () {
    $joint = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id, 'title' => 'Joint Venture']);
    $jointTeam = $joint->ensureTeam();
    $jointTeam->update(['is_cross_organization' => true]);
    $jointTeam->members()->attach($this->pm->id, ['role' => 'leader']);
    $task = Task::factory()->create(['project_id' => $joint->id]);

    foreach ([$this->pmUser, $this->jsUser] as $viewer) {
        qaeQuickAdd($this, $viewer, $joint, $this->outsider, 'Across the border')
            ->assertRedirect('/workspace')->assertSessionHasErrors();

        $this->actingAs($viewer)->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $this->outsider->id])
            ->assertSessionHasErrors('member_id');
    }

    expect(Task::query()->where('title', 'Across the border')->exists())->toBeFalse()
        ->and($task->assignments()->count())->toBe(0)
        ->and(qaeOnTeam($jointTeam, $this->outsider))->toBeFalse();

    // Their own organization's people still join through it.
    $this->actingAs($this->jsUser)->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $this->colleague->id])
        ->assertSessionHasNoErrors();

    expect(qaeOnTeam($jointTeam, $this->colleague))->toBeTrue();
});

// ---- What the form and the task page offer ----

it('shares who each viewer may add from the task form', function () {
    $ids = fn ($rows): array => collect($rows)->pluck('id')->all();

    $this->actingAs($this->pmUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quickAddContext.startGeneral', false)
            ->where('quickAddContext.projects', fn ($projects): bool => collect($projects)->firstWhere('id', $this->project->id)['can_add_people'] === true)
            ->where('quickAddContext.organizationPeople', fn ($people): bool => in_array($this->colleague->id, $ids($people), true)
                && ! in_array($this->retired->id, $ids($people), true)
                && ! in_array($this->outsider->id, $ids($people), true)
                && collect($people)->firstWhere('id', $this->colleague->id) == ['id' => $this->colleague->id, 'name' => 'Sita Section Officer', 'title' => 'Section Officer']));

    $this->actingAs($this->officerUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quickAddContext.startGeneral', false)
            ->where('quickAddContext.projects', fn ($projects): bool => collect($projects)->firstWhere('id', $this->project->id)['can_add_people'] === false)
            ->where('quickAddContext.organizationPeople', []));

    $this->actingAs($this->emptyAdminUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quickAddContext.startGeneral', true)
            ->where('quickAddContext.organizationPeople', fn ($people): bool => in_array($this->emptyPerson->id, $ids($people), true)
                && ! in_array($this->colleague->id, $ids($people), true)));

    $this->actingAs($this->emptyPersonUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quickAddContext.startGeneral', false)
            ->where('quickAddContext.organizationPeople', []));

    $this->actingAs($this->adminUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('quickAddContext.startGeneral', false));

    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quickAddContext.startGeneral', false)
            ->where('quickAddContext.organizationPeople', []));
});

it('offers the organization on the task page to whoever may add people, apart from the team', function () {
    $task = Task::factory()->create(['project_id' => $this->project->id]);
    $ids = fn ($rows): array => collect($rows)->pluck('id')->all();
    $url = "/workspace/projects/{$this->project->slug}/tasks/{$task->slug}";

    $this->actingAs($this->pmUser)->get($url)
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('team', fn ($team): bool => collect($ids($team))->sort()->values()->all() === collect([$this->pm->id, $this->officer->id])->sort()->values()->all())
            ->where('joinCandidates', fn ($people): bool => in_array($this->colleague->id, $ids($people), true)
                && array_intersect($ids($people), [$this->pm->id, $this->officer->id, $this->retired->id, $this->outsider->id]) === [])
            // A mention notifies; people off the team cannot see the task.
            ->where('mentionCandidates', fn ($people): bool => ! in_array($this->colleague->id, $ids($people), true)));

    $this->actingAs($this->officerUser)->get($url)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('joinCandidates', []));

    $preview = $this->actingAs($this->pmUser)->getJson("/workspace/tasks/{$task->id}/preview")->assertOk();
    expect($ids($preview->json('data.join_candidates')))->toContain($this->colleague->id)
        ->not->toContain($this->officer->id);

    $this->actingAs($this->officerUser)->getJson("/workspace/tasks/{$task->id}/preview")
        ->assertOk()->assertJsonPath('data.join_candidates', []);
});

it('builds the task form context without a query per project for a program manager', function () {
    $this->actingAs($this->jsUser);
    $count = function (): int {
        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries++;
        });
        $this->get('/workspace/guide')->assertOk();

        return $queries;
    };

    $before = $count();

    // More programs and projects for them to see: each with its own manager,
    // none led by the program manager.
    $second = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->js->id]);

    foreach (range(1, 4) as $i) {
        $project = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $i % 2 === 0 ? $this->program->id : $second->id]);
        $project->ensureTeam()->members()->attach(Member::factory()->create(['organization_id' => $this->org->id])->id, ['role' => 'leader']);
    }

    expect($count())->toBe($before);

    // Still the right answer for each of them.
    $this->get('/workspace/guide')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('quickAddContext.projects', fn ($projects): bool => collect($projects)->count() === 5
            && collect($projects)->every(fn (array $p): bool => $p['can_add_people'] === true)));
});

it('answers canManageRosterOfProject exactly as canManageRosterOf answers for the project team', function () {
    $otherProgram = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $this->colleague->id]);
    $joint = Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $this->program->id]);
    $joint->ensureTeam()->update(['is_cross_organization' => true]);
    $joint->ensureTeam()->members()->attach($this->outsider->id, ['role' => 'leader']);
    $projects = [
        $this->project,
        $joint,
        Project::factory()->create(['organization_id' => $this->org->id, 'program_id' => $otherProgram->id]),
        Project::factory()->create(['organization_id' => $this->org->id]),
        // Inconsistent on purpose: another organization's project naming this program.
        Project::factory()->create(['organization_id' => $this->otherOrg->id, 'program_id' => $this->program->id]),
    ];
    $viewers = [
        User::factory()->create(['email' => 'boss@example.com']), $this->adminUser, $this->jsUser, $this->pmUser,
        $this->officerUser, $this->colleagueUser, $this->outsider->user, $this->emptyAdminUser,
    ];

    foreach ($projects as $project) {
        $team = $project->ensureTeam();

        foreach ($viewers as $viewer) {
            expect(WorkspaceAccess::canManageRosterOfProject($viewer, $project->fresh()))
                ->toBe(WorkspaceAccess::canManageRosterOf($viewer, $team));
        }
    }
});
