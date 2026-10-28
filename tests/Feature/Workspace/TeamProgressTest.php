<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');

    $this->organization = Organization::factory()->create();
    $this->team = Team::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Delivery']);

    $this->leader = User::factory()->create();
    $this->leaderMember = Member::factory()->linkedTo($this->leader)->create(['organization_id' => $this->organization->id, 'name' => 'Asha Leader']);
    $this->worker = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bikash Worker']);
    $this->team->members()->attach([
        $this->leaderMember->id => ['role' => 'leader'],
        $this->worker->id => ['role' => 'member'],
    ]);

    $this->project = Project::factory()->create(['organization_id' => $this->organization->id]);
    $this->project->teams()->attach($this->team->id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function progressTask(Project $project, array $attributes, array $assignees = []): Task
{
    $task = Task::factory()->create(['project_id' => $project->id] + $attributes);

    foreach ($assignees as $member) {
        ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id, 'personal_progress' => 40]);
    }

    return $task;
}

it('shows the team\'s leader each member\'s workload and the team\'s tasks', function () {
    progressTask($this->project, ['status' => 'in_progress', 'deadline_at' => '2026-10-20'], [$this->worker]);
    progressTask($this->project, ['status' => 'in_progress', 'deadline_at' => '2026-10-31'], [$this->worker]);
    progressTask($this->project, ['status' => 'done', 'deadline_at' => '2026-10-10', 'completed_at' => '2026-10-09'], [$this->worker, $this->leaderMember]);

    $archived = Project::factory()->create(['organization_id' => $this->organization->id, 'archived_at' => now()]);
    $archived->teams()->attach($this->team->id);
    progressTask($archived, ['title' => 'Archived work']);

    $elsewhere = Project::factory()->create(['organization_id' => $this->organization->id]);
    progressTask($elsewhere, ['title' => 'Another team\'s work'], [$this->worker]);

    $this->actingAs($this->leader)->get("/workspace/teams/{$this->team->id}/progress")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Team/Progress')
            ->where('progress.team.name', 'Delivery')
            ->has('progress.tasks', 3)
            ->where('progress.members.0.name', 'Asha Leader')
            ->where('progress.members.0.role', 'leader')
            ->where('progress.members.0.done', 1)
            ->where('progress.members.1.name', 'Bikash Worker')
            ->where('progress.members.1.open', 2)
            ->where('progress.members.1.done', 1)
            ->where('progress.members.1.overdue', 1)
            ->where('progress.members.1.due_this_week', 1)
            ->where('progress.members.1.progress', 60));
});

it('lays tasks out from creation to completion, or to the deadline while open', function () {
    $open = progressTask($this->project, ['status' => 'in_progress', 'deadline_at' => '2026-11-15']);
    $open->forceFill(['created_at' => '2026-10-01 09:00:00'])->save();
    $done = progressTask($this->project, ['status' => 'done', 'deadline_at' => '2026-10-20', 'completed_at' => '2026-10-25 12:00:00']);
    $done->forceFill(['created_at' => '2026-10-02 09:00:00'])->save();

    Sanctum::actingAs($this->leader, ['*']);
    $tasks = collect($this->getJson("/api/v1/workspace/teams/{$this->team->id}/progress")->assertOk()->json('data.tasks'))->keyBy('id');

    expect($tasks[$open->id])->toMatchArray(['start' => '2026-10-01', 'end' => '2026-11-15', 'is_complete' => false])
        ->and($tasks[$done->id])->toMatchArray(['start' => '2026-10-02', 'end' => '2026-10-25', 'is_complete' => true, 'is_late' => true, 'progress' => 100]);
});

it('opens the progress page to the organization\'s office admin and super-admins', function () {
    $admin = User::factory()->create();
    Member::factory()->linkedTo($admin)->create(['organization_id' => $this->organization->id, 'is_office_admin' => true]);

    $this->actingAs($admin)->get("/workspace/teams/{$this->team->id}/progress")->assertOk();
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))->get("/workspace/teams/{$this->team->id}/progress")->assertOk();
});

it('keeps the progress page from plain members, other leaders and other organizations', function () {
    $plain = User::factory()->create();
    $this->team->members()->attach(Member::factory()->linkedTo($plain)->create(['organization_id' => $this->organization->id])->id);

    $otherLeader = User::factory()->create();
    $otherTeam = Team::factory()->create(['organization_id' => $this->organization->id]);
    $otherTeam->members()->attach(Member::factory()->linkedTo($otherLeader)->create(['organization_id' => $this->organization->id])->id, ['role' => 'leader']);

    $foreignAdmin = User::factory()->create();
    Member::factory()->linkedTo($foreignAdmin)->create(['organization_id' => Organization::factory()->create()->id, 'is_office_admin' => true]);

    foreach ([$plain, $otherLeader, $foreignAdmin] as $user) {
        $this->actingAs($user)->get("/workspace/teams/{$this->team->id}/progress")->assertForbidden();
    }

    Sanctum::actingAs($plain, ['*']);
    $this->getJson("/api/v1/workspace/teams/{$this->team->id}/progress")->assertForbidden();
});
