<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('lists only visible projects on the web index', function () {
    $user = User::factory()->create();
    $member = Member::factory()->linkedTo($user)->create();
    $team = Team::factory()->create();
    $team->members()->attach($member->id, ['role' => 'member']);

    $mine = Project::factory()->create(['is_public' => false, 'title' => 'Mine']);
    $mine->teams()->attach($team->id);
    $foreign = Project::factory()->create(['is_public' => false, 'title' => 'Foreign']);
    $foreign->teams()->attach(Team::factory()->create()->id);

    $this->actingAs($user)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('projects', fn (Collection $rows): bool => $rows->pluck('id')->contains($mine->id)
                && ! $rows->pluck('id')->contains($foreign->id))
            ->where('canCreate', false));
});

it('exposes led teams + canCreate to a leader on the index', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    $team = Team::factory()->create(['name' => 'IT']);
    $team->members()->attach($leader->id, ['role' => 'leader']);

    $this->actingAs($leaderUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canCreate', true)
            ->where('assignableTeams', fn (Collection $t): bool => $t->pluck('id')->contains($team->id)));
});

it('scopes the JSON API index too', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $foreign = Project::factory()->create(['is_public' => false]);
    $foreign->teams()->attach(Team::factory()->create()->id);

    $this->actingAs($user)->getJson('/api/v1/workspace/projects')
        ->assertOk()
        ->assertJsonMissing(['id' => $foreign->id]);
});
