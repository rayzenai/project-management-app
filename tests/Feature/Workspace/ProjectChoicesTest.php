<?php

use App\Models\Form;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use App\Support\ProjectChoices;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * Every list where someone chooses a project to put something in (a new
 * task, a new form, a form's copy and its tasks, where new documents go,
 * moving a document) has one shape, ProjectChoices, read by one picker
 * (components/ProjectPicker.svelte): each project's id, slug, title,
 * program and organization, `mine` when the viewer is on its team ("Your
 * projects"), and those projects' organizations by name.
 */

beforeEach(function () {
    config([
        'project-management.super_admins' => ['boss@example.com'],
        'forms.enabled' => true,
        'documents.enabled' => true,
        'documents.server_url' => 'https://docs.example',
        'documents.jwt_secret' => DOCS_SECRET,
    ]);
    Storage::fake('documents');

    $this->office = Organization::factory()->create(['name' => 'SDOC']);
    $this->ministry = Organization::factory()->create(['name' => 'Ministry of Health']);
    $revenue = Program::factory()->create(['organization_id' => $this->office->id, 'name' => 'Revenue']);
    $this->surveys = Project::factory()->create(['organization_id' => $this->office->id, 'title' => 'Surveys', 'program_id' => $revenue->id]);
    $this->archive = Project::factory()->create(['organization_id' => $this->office->id, 'title' => 'Archive']);
    $this->clinics = Project::factory()->create(['organization_id' => $this->ministry->id, 'title' => 'Clinics']);

    $this->member = docsPerson($this->office, $this->surveys, 'member');
    $this->admin = docsPerson($this->office, null, null, ['is_office_admin' => true]);

    $this->props = function (User $user, string $url): array {
        $props = null;

        $this->actingAs($user)->get($url)->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $props = $page->toArray()['props'];
            });

        return $props;
    };
});

function choiceRow(Project $project, bool $mine): array
{
    return [
        'id' => $project->id,
        'slug' => $project->slug,
        'title' => $project->title,
        'program' => $project->program?->name,
        'organization_id' => $project->organization_id,
        'mine' => $mine,
    ];
}

it('marks the projects whose team the viewer is on and names their organizations', function () {
    $projects = Project::query()->with('program')->orderBy('title')->get();

    expect(ProjectChoices::for($this->member, $projects))->toEqual([
        'projects' => [
            choiceRow($this->archive, false),
            choiceRow($this->clinics, false),
            choiceRow($this->surveys, true),
        ],
        'organizations' => [
            ['id' => $this->ministry->id, 'name' => 'Ministry of Health'],
            ['id' => $this->office->id, 'name' => 'SDOC'],
        ],
    ]);
});

it('marks nothing as theirs for a login with no person, and lists nothing from no projects', function () {
    $boss = User::factory()->create(['email' => 'boss@example.com']);
    $projects = Project::query()->with('program')->orderBy('title')->get();

    expect(collect(ProjectChoices::for($boss, $projects)['projects'])->pluck('mine')->unique()->all())->toBe([false])
        ->and(ProjectChoices::for($this->member, collect()))->toEqual(['projects' => [], 'organizations' => []]);
});

it('gives the Forms page the projects a new form may go in, in that shape', function () {
    expect(($this->props)($this->member, '/workspace/forms')['places'])->toEqual([
        'projects' => [choiceRow($this->surveys, true)],
        'organizations' => [['id' => $this->office->id, 'name' => 'SDOC']],
    ])->and(($this->props)($this->admin, '/workspace/forms')['places']['projects'])->toEqual([
        choiceRow($this->archive, false),
        choiceRow($this->surveys, false),
    ]);
});

it('gives the builder where a copy and where its tasks may go, in that shape', function () {
    $this->actingAs($this->admin)->post('/workspace/forms', ['project' => $this->surveys->slug, 'title' => 'Staff survey'])->assertRedirect();
    $form = Form::query()->latest('id')->firstOrFail();

    $props = ($this->props)($this->admin, "/workspace/forms/{$form->id}/edit");

    expect($props['places']['projects'])->toEqual([
        choiceRow($this->archive, false),
        choiceRow($this->surveys, false),
    ])->and($props['tasks']['places'])->toEqual([
        'projects' => [choiceRow($this->archive, false), choiceRow($this->surveys, false)],
        'organizations' => [['id' => $this->office->id, 'name' => 'SDOC']],
    ])->and($props['tasks'])->not->toHaveKey('projects');
});

it('gives the Documents page where new documents go and where one may move, in that shape', function () {
    expect(($this->props)($this->member, '/workspace/documents')['places'])->toEqual([
        'projects' => [choiceRow($this->surveys, true)],
        'organizations' => [['id' => $this->office->id, 'name' => 'SDOC']],
    ]);
});

it('marks the add field\'s projects the same way, beside what quick add needs', function () {
    $projects = collect(($this->props)($this->admin, '/workspace/my')['quickAddContext']['projects'])->keyBy('slug');
    $mine = collect(($this->props)($this->member, '/workspace/my')['quickAddContext']['projects'])->keyBy('slug');

    expect($projects[$this->surveys->slug])->toMatchArray(choiceRow($this->surveys, false))
        ->toHaveKeys(['member_ids', 'can_add_people'])
        ->and($mine[$this->surveys->slug]['mine'])->toBeTrue();
});
