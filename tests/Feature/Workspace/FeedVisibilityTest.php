<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('omits invisible projects from the home rollup', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $foreign = Project::factory()->create(['is_public' => false, 'title' => 'Foreign']);
    $foreign->teams()->attach(Team::factory()->create()->id);

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('projects', fn (Collection $rows): bool => ! $rows->pluck('slug')->contains($foreign->slug)));
});

it('omits invisible projects from My Workspace project picker', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $foreign = Project::factory()->create(['is_public' => false]);
    $foreign->teams()->attach(Team::factory()->create()->id);

    $this->actingAs($user)->get('/workspace/my')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('projects', fn (Collection $rows): bool => ! $rows->pluck('id')->contains($foreign->id)));
});
