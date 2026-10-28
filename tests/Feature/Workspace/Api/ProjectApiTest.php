<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    Sanctum::actingAs(User::factory()->create(['email' => 'boss@example.com']), ['*']);
});

it('creates a project and returns the {message, data} envelope', function () {
    $response = $this->postJson('/api/v1/workspace/projects', [
        'organization_id' => OrganizationFactory::defaultId(),
        'title' => 'Coastal Cleanup',
        'description' => 'A tidy shoreline.',
        'is_public' => true,
        'manager_id' => Member::factory()->create(['organization_id' => OrganizationFactory::defaultId()])->id,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'data' => ['id', 'slug', 'title']])
        ->assertJsonPath('data.title', 'Coastal Cleanup');

    expect(Project::query()->where('title', 'Coastal Cleanup')->exists())->toBeTrue();
    expect($response->json('data.slug'))->toBeString()->not->toBeEmpty();
});

it('ignores is_public and the manager in a JSON body from a project\'s manager', function () {
    $managerUser = User::factory()->create();
    $manager = Member::factory()->linkedTo($managerUser)->create();
    $project = Project::factory()->create(['title' => 'JSON Sneaky']);
    $team = $project->ensureTeam();
    $team->members()->attach($manager->id, ['role' => 'leader']);
    $friend = Member::factory()->create(['organization_id' => $project->organization_id]);

    Sanctum::actingAs($managerUser, ['*']);

    $this->patchJson("/api/v1/workspace/projects/{$project->slug}", [
        'is_public' => true,
        'manager_id' => $friend->id,
    ])->assertOk();

    expect($project->fresh()->is_public)->toBeFalse()
        ->and($project->fresh()->manager()?->id)->toBe($manager->id);
});

it('shows a project by slug', function () {
    $project = Project::factory()->public()->create(['title' => 'Bridge Build']);

    $this->getJson("/api/v1/workspace/projects/{$project->slug}")
        ->assertOk()
        ->assertJsonPath('project.slug', $project->slug)
        ->assertJsonPath('project.title', 'Bridge Build');
});

it('rejects creating a project with no title', function () {
    $this->postJson('/api/v1/workspace/projects', ['description' => 'no title'])
        ->assertUnprocessable()
        ->assertJsonStructure(['errors'])
        ->assertJsonValidationErrors('title');
});
