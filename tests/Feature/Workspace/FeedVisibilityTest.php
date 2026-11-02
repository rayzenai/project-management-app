<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it("omits invisible projects from the app's home rollup", function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $foreign = Project::factory()->create(['is_public' => false, 'title' => 'Foreign']);
    $foreign->teams()->attach(Team::factory()->create()->id);

    $projects = $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspace/home')->assertOk()->json('projects');

    expect(collect($projects)->pluck('slug'))->not->toContain($foreign->slug);
});

it("omits invisible projects from the app's My Workspace project picker", function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $foreign = Project::factory()->create(['is_public' => false]);
    $foreign->teams()->attach(Team::factory()->create()->id);

    $projects = $this->actingAs($user, 'sanctum')->getJson('/api/v1/workspace/my')->assertOk()->json('projects');

    expect(collect($projects)->pluck('id'))->not->toContain($foreign->id);
});
