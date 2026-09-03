<?php

use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('includes teams and can_manage_access', function () {
    $admin = User::factory()->create(['email' => 'boss@example.com']);
    $project = Project::factory()->create();
    $team = Team::factory()->create(['name' => 'IT']);
    $project->teams()->attach($team->id);
    $project->load('teams');

    $array = (new ProjectResource($project))->toArray(Request::create('/')->setUserResolver(fn () => $admin));

    expect($array['can_manage_access'])->toBeTrue()
        ->and(collect($array['teams'])->pluck('name'))->toContain('IT');
});
