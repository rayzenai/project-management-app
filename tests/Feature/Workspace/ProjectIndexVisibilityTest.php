<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
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

it('groups by manager: each project carries its manager and how its work stands', function () {
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');
    $adminUser = User::factory()->create();
    $project = Project::factory()->create(['title' => 'Portal']);
    Member::factory()->linkedTo($adminUser)->create(['organization_id' => $project->organization_id, 'is_office_admin' => true]);
    $manager = Member::factory()->create(['organization_id' => $project->organization_id, 'name' => 'Meera Manager']);
    $project->ensureTeam()->members()->attach($manager->id, ['role' => 'leader']);
    Task::factory()->create(['project_id' => $project->id, 'status' => 'done']);
    Task::factory()->create(['project_id' => $project->id, 'status' => 'in_progress', 'deadline_at' => '2026-10-20']);
    Task::factory()->create(['project_id' => $project->id, 'status' => 'failed', 'deadline_at' => '2026-10-21']);
    Task::factory()->create(['project_id' => $project->id, 'status' => 'not_started', 'deadline_at' => '2026-11-05']);

    $this->actingAs($adminUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canCreate', true)
            ->where('managerCandidates', fn (Collection $people): bool => $people->pluck('name')->contains('Meera Manager'))
            ->where('projects.0.manager.name', 'Meera Manager')
            ->where('projects.0.percent_complete', 25)
            ->where('projects.0.open_tasks_count', 3)
            ->where('projects.0.overdue_tasks_count', 1)
            ->where('projects.0.next_deadline_at', fn ($at) => str_starts_with((string) $at, '2026-11-05')));

    CarbonImmutable::setTestNow();
});

it('offers project managers no way to create projects', function () {
    $leaderUser = User::factory()->create();
    $leader = Member::factory()->linkedTo($leaderUser)->create();
    Team::factory()->create()->members()->attach($leader->id, ['role' => 'leader']);

    $this->actingAs($leaderUser)->get('/workspace/projects')
        ->assertInertia(fn (AssertableInertia $p) => $p
            ->where('canCreate', false)
            ->where('managerCandidates', []));
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
