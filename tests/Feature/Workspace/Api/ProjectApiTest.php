<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    Sanctum::actingAs(User::factory()->create(['email' => 'boss@example.com']), ['*']);
});

it('creates a project and returns the {message, data} envelope', function () {
    $response = $this->postJson('/api/v1/workspace/projects', [
        'title' => 'Coastal Cleanup',
        'description' => 'A tidy shoreline.',
        'is_public' => true,
    ]);

    $response->assertCreated()
        ->assertJsonStructure(['message', 'data' => ['id', 'slug', 'title']])
        ->assertJsonPath('data.title', 'Coastal Cleanup');

    expect(Project::query()->where('title', 'Coastal Cleanup')->exists())->toBeTrue();
    expect($response->json('data.slug'))->toBeString()->not->toBeEmpty();
});

it('ignores is_public in a JSON body from a non-super-admin', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $team = Team::factory()->create();
    $team->members()->attach($leader->id, ['role' => 'leader']);

    Sanctum::actingAs($leaderUser, ['*']);

    $this->postJson('/api/v1/workspace/projects', [
        'title' => 'JSON Sneaky',
        'team_ids' => [$team->id],
        'is_public' => true,
    ])->assertCreated();

    expect(Project::query()->where('title', 'JSON Sneaky')->firstOrFail()->is_public)->toBeFalse();
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
