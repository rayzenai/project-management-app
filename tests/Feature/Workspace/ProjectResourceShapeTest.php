<?php

use App\Http\Resources\ProjectResource;
use App\Models\Member;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('includes its team, manager and can_manage_access', function () {
    $admin = User::factory()->create(['email' => 'boss@example.com']);
    $project = Project::factory()->create();
    $team = $project->ensureTeam();
    $team->members()->attach(Member::factory()->create(['name' => 'Meera Manager'])->id, ['role' => 'leader']);
    $project->load('teams');

    $array = (new ProjectResource($project))->toArray(Request::create('/')->setUserResolver(fn () => $admin));

    expect($array['can_manage_access'])->toBeTrue()
        ->and($array['can_choose_manager'])->toBeTrue()
        ->and($array['team']['id'])->toBe($team->id)
        ->and($array['team']['members_count'])->toBe(1)
        ->and($array['manager']['name'])->toBe('Meera Manager');
});
