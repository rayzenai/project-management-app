<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = OrganizationFactory::defaultId();
    $this->admin = User::factory()->create();
    Member::factory()->linkedTo($this->admin)->create(['organization_id' => $this->org, 'is_office_admin' => true]);
    $this->manager = Member::factory()->create(['organization_id' => $this->org]);
});

it('lets an office admin put a project in a program and take it out', function () {
    $one = Program::factory()->create(['organization_id' => $this->org, 'name' => 'Alpha']);
    $two = Program::factory()->create(['organization_id' => $this->org, 'name' => 'Beta']);

    $this->actingAs($this->admin)->post('/workspace/projects', [
        'title' => 'Roadmap',
        'manager_id' => $this->manager->id,
        'program_id' => $one->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $project = Project::query()->where('title', 'Roadmap')->firstOrFail();
    expect($project->program_id)->toBe($one->id);

    $this->patch("/workspace/projects/{$project->slug}", ['program_id' => $two->id])->assertSessionHasNoErrors();
    expect($project->fresh()->program_id)->toBe($two->id);

    $this->get("/workspace/projects/{$project->slug}")
        ->assertInertia(fn ($page) => $page->where('project.program.slug', $two->slug)->where('project.program.name', 'Beta'));

    $this->patch("/workspace/projects/{$project->slug}", ['program_id' => null])->assertSessionHasNoErrors();
    expect($project->fresh()->program_id)->toBeNull();

    $this->get("/workspace/projects/{$project->slug}")
        ->assertInertia(fn ($page) => $page->where('project.program', null));

    $descriptions = ProjectActivity::query()->where('project_id', $project->id)->orderBy('id')->pluck('description')->all();

    expect($descriptions)->toContain('Started in the Alpha program')
        ->toContain('Moved from the Alpha program to the Beta program')
        ->toContain('Taken out of the Beta program');
});

it('puts a project in a program over the API', function () {
    $program = Program::factory()->create(['organization_id' => $this->org]);
    Sanctum::actingAs($this->admin, ['*']);

    $this->postJson('/api/v1/workspace/projects', [
        'title' => 'Wired', 'manager_id' => $this->manager->id, 'program_id' => $program->id,
    ])->assertCreated()->assertJsonPath('data.program.slug', $program->slug);

    $project = Project::query()->where('title', 'Wired')->firstOrFail();

    $this->patchJson("/api/v1/workspace/projects/{$project->slug}", ['program_id' => null])
        ->assertOk()->assertJsonPath('data.program', null);
});

it('moves access with the project', function () {
    $oneManagerUser = User::factory()->create();
    $twoManagerUser = User::factory()->create();
    $one = Program::factory()->create([
        'organization_id' => $this->org, 'name' => 'P1',
        'manager_id' => Member::factory()->linkedTo($oneManagerUser)->create(['organization_id' => $this->org])->id,
    ]);
    $two = Program::factory()->create([
        'organization_id' => $this->org, 'name' => 'P2',
        'manager_id' => Member::factory()->linkedTo($twoManagerUser)->create(['organization_id' => $this->org])->id,
    ]);
    $project = Project::factory()->create(['organization_id' => $this->org, 'program_id' => $one->id]);
    $project->ensureTeam();

    $this->actingAs($oneManagerUser)->patch("/workspace/projects/{$project->slug}", ['title' => 'Mine'])->assertRedirect();

    $this->actingAs($this->admin)->patch("/workspace/projects/{$project->slug}", ['program_id' => $two->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($oneManagerUser)->patch("/workspace/projects/{$project->slug}", ['title' => 'Still mine'])->assertForbidden();
    $this->actingAs($twoManagerUser)->patch("/workspace/projects/{$project->slug}", ['title' => 'Now mine'])->assertRedirect();

    expect(ProjectActivity::query()->where('project_id', $project->id)->pluck('description')->all())
        ->toContain('Moved from the P1 program to the P2 program');
});

it('refuses a program of another organization', function () {
    $other = Program::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $project = Project::factory()->create(['organization_id' => $this->org]);

    $this->actingAs($this->admin)->from('/workspace/projects')
        ->post('/workspace/projects', ['title' => 'X', 'manager_id' => $this->manager->id, 'program_id' => $other->id])
        ->assertSessionHasErrors('program_id');

    $this->patch("/workspace/projects/{$project->slug}", ['program_id' => $other->id])
        ->assertSessionHasErrors('program_id');

    expect($project->fresh()->program_id)->toBeNull();
});

it('ignores program_id from anyone who does not administer the organization', function () {
    $programManagerUser = User::factory()->create();
    $mine = Program::factory()->create([
        'organization_id' => $this->org,
        'manager_id' => Member::factory()->linkedTo($programManagerUser)->create(['organization_id' => $this->org])->id,
    ]);
    $other = Program::factory()->create(['organization_id' => $this->org]);
    $project = Project::factory()->create(['organization_id' => $this->org, 'program_id' => $mine->id]);

    $leaderUser = User::factory()->create();
    $project->ensureTeam()->members()->attach(
        Member::factory()->linkedTo($leaderUser)->create(['organization_id' => $this->org])->id,
        ['role' => 'leader'],
    );

    $this->actingAs($leaderUser)->patch("/workspace/projects/{$project->slug}", ['title' => 'A', 'program_id' => $other->id])
        ->assertSessionHasNoErrors();
    expect($project->fresh()->program_id)->toBe($mine->id);

    $this->actingAs($programManagerUser)->patch("/workspace/projects/{$project->slug}", ['title' => 'B', 'program_id' => null])
        ->assertSessionHasNoErrors();
    expect($project->fresh()->program_id)->toBe($mine->id)->and($project->fresh()->title)->toBe('B');
});
