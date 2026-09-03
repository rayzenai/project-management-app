<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => config(['project-management.super_admins' => ['boss@example.com']]));

it('excludes invisible projects/tasks from search', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $foreign = Project::factory()->create(['is_public' => false, 'title' => 'Foreign Roadmap']);
    $foreign->teams()->attach(Team::factory()->create()->id);
    Task::factory()->create(['project_id' => $foreign->id, 'title' => 'Foreign Roadmap Task']);

    $this->actingAs($user)->getJson('/workspace/search?q=Roadmap')
        ->assertOk()
        ->assertJsonMissing(['slug' => $foreign->slug]);
});

it('excludes invisible projects from the quick-add picker', function () {
    $user = User::factory()->create();
    Member::factory()->linkedTo($user)->create();
    $foreign = Project::factory()->create(['is_public' => false]);
    $foreign->teams()->attach(Team::factory()->create()->id);

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('quickAddContext.projects', fn (Collection $rows): bool => ! $rows->pluck('id')->contains($foreign->id)));
});
