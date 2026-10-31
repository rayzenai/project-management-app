<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\User;
use App\Queries\ProgramProgressQuery;
use App\Services\Workspace\CreateProgramService;
use App\Services\Workspace\CreateProjectService;
use App\Support\ServiceResult;
use Inertia\Testing\AssertableInertia;

/*
 * Every program starts with a project titled "General", managed by the
 * program manager, so a new program manager can add a task and assign
 * someone straight away.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
    $this->otherOrg = Organization::factory()->create(['name' => 'Elsewhere']);

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);

    $this->jsUser = User::factory()->create();
    $this->js = Member::factory()->linkedTo($this->jsUser)->designated('Joint Secretary')->create(['organization_id' => $this->org->id]);
    $this->officer = Member::factory()->create(['organization_id' => $this->org->id]);
});

function makeProgram($test, string $name = 'Revenue'): Program
{
    $test->actingAs($test->adminUser)->post('/workspace/programs', [
        'name' => $name,
        'manager_id' => $test->js->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    return Program::query()->where('name', $name)->firstOrFail();
}

it('creates a General project with every program', function () {
    $program = makeProgram($this);

    $general = Project::query()->where('program_id', $program->id)->sole();

    expect($general->title)->toBe('General')
        ->and($general->organization_id)->toBe($this->org->id)
        ->and($general->is_public)->toBeFalse()
        ->and($general->key)->not->toBeEmpty()
        ->and($general->manager()?->id)->toBe($this->js->id)
        ->and(ProjectActivity::query()->where('project_id', $general->id)->pluck('description')->all())
        ->toContain('Started in the Revenue program');

    // A second program gets its own, with a free slug and key.
    $second = makeProgram($this, 'Customs');
    $other = Project::query()->where('program_id', $second->id)->sole();
    expect($other->title)->toBe('General')->and($other->id)->not->toBe($general->id)
        ->and($other->key)->not->toBe($general->key);
});

it('lets the program manager add a task to General and assign someone on its team', function () {
    $program = makeProgram($this);
    $general = Project::query()->where('program_id', $program->id)->sole();
    $general->ensureTeam()->members()->attach($this->officer->id, ['role' => 'member']);

    $this->actingAs($this->jsUser)->post("/workspace/projects/{$general->slug}/tasks", ['title' => 'First task'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $task = Task::query()->where('project_id', $general->id)->sole();

    $this->actingAs($this->jsUser)->post("/workspace/tasks/{$task->id}/assignments", ['member_id' => $this->officer->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($task->assignments()->where('member_id', $this->officer->id)->exists())->toBeTrue();
});

it('creates nothing when the program is refused', function () {
    $inactive = Member::factory()->create(['organization_id' => $this->org->id, 'is_active' => false]);
    $outsider = Member::factory()->create(['organization_id' => $this->otherOrg->id]);

    foreach ([$inactive, $outsider] as $manager) {
        $this->actingAs($this->adminUser)->post('/workspace/programs', ['name' => 'Refused', 'manager_id' => $manager->id])
            ->assertSessionHasErrors('manager_id');
    }

    expect(Program::query()->where('name', 'Refused')->exists())->toBeFalse()
        ->and(Project::query()->where('title', 'General')->exists())->toBeFalse();
});

it('rolls the program back when General cannot be made', function () {
    $this->app->instance(CreateProjectService::class, new class extends CreateProjectService
    {
        public function __construct() {}

        public function execute(array $attributes): ServiceResult
        {
            return ServiceResult::failure('No.', 500);
        }
    });

    $result = app(CreateProgramService::class)->execute([
        'name' => 'Doomed',
        'organization_id' => $this->org->id,
        'manager_id' => $this->js->id,
    ]);

    expect($result->success)->toBeFalse()
        ->and(Program::query()->where('name', 'Doomed')->exists())->toBeFalse();
});

it('labels quick-add projects with their program', function () {
    $program = makeProgram($this);
    $loose = Project::factory()->create(['organization_id' => $this->org->id, 'title' => 'Loose']);
    $loose->ensureTeam()->members()->attach($this->js->id, ['role' => 'leader']);

    $this->actingAs($this->adminUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('quickAddContext.projects', function ($projects) use ($program): bool {
                $rows = collect($projects);

                return $rows->firstWhere('title', 'General')['program'] === $program->name
                    && $rows->firstWhere('title', 'Loose')['program'] === null;
            }));
});

it('shows New project on the program page to its manager and admins only', function () {
    $program = makeProgram($this);
    $stranger = User::factory()->create();
    $strangerMember = Member::factory()->linkedTo($stranger)->create(['organization_id' => $this->org->id]);
    $otherProgram = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $strangerMember->id]);

    foreach ([$this->jsUser, $this->adminUser] as $viewer) {
        $this->actingAs($viewer)->get("/workspace/programs/{$program->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('progress.program.can_create_project', true)
                ->where('managerCandidates', fn ($c): bool => collect($c)->pluck('id')->contains($this->officer->id)));
    }

    // Another program's manager cannot even open it; theirs shows the button for theirs only.
    $this->actingAs($stranger)->get("/workspace/programs/{$program->slug}")->assertForbidden();
    $this->actingAs($stranger)->get("/workspace/programs/{$otherProgram->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('progress.program.can_create_project', true));

    // Team membership never grants a view of the program page.
    $general = Project::query()->where('program_id', $program->id)->sole();
    $general->ensureTeam()->members()->attach($strangerMember->id, ['role' => 'member']);
    $this->actingAs($stranger)->get("/workspace/programs/{$program->slug}")->assertForbidden();

    // Nobody who can neither manage nor create in the program is offered candidates.
    $otherAdmin = User::factory()->create();
    Member::factory()->linkedTo($otherAdmin)->create(['organization_id' => $this->otherOrg->id, 'is_office_admin' => true]);

    $query = app(ProgramProgressQuery::class);
    expect($query->managerCandidates($program, $stranger))->toBe([])
        ->and($query->managerCandidates($program, $otherAdmin))->toBe([])
        ->and($query->managerCandidates($program, $this->jsUser))->not->toBe([]);

    $this->actingAs($otherAdmin)->get("/workspace/programs/{$program->slug}")->assertForbidden();
});

it('rolls the program back when General would have no manager', function () {
    $gone = Member::factory()->create(['organization_id' => $this->org->id]);
    $id = $gone->id;
    $gone->delete();

    $result = app(CreateProgramService::class)->execute([
        'name' => 'Leaderless',
        'organization_id' => $this->org->id,
        'manager_id' => $id,
    ]);

    expect($result->success)->toBeFalse()
        ->and(Program::query()->where('name', 'Leaderless')->exists())->toBeFalse()
        ->and(Project::query()->where('title', 'General')->exists())->toBeFalse();
});
