<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-10-28 10:00:00');

    $this->organization = Organization::factory()->create();
    $this->team = Team::factory()->create(['organization_id' => $this->organization->id]);

    $this->leader = User::factory()->create();
    $this->leaderMember = Member::factory()->linkedTo($this->leader)->create(['organization_id' => $this->organization->id, 'name' => 'Asha Leader']);
    $this->worker = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Bikash Worker']);
    $this->idle = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Chhaya Idle']);
    $this->team->members()->attach([
        $this->leaderMember->id => ['role' => 'leader'],
        $this->worker->id => ['role' => 'member'],
        $this->idle->id => ['role' => 'member'],
    ]);

    $this->project = Project::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Ward Upgrade']);
    $this->project->teams()->attach($this->team->id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

function projectProgressTask(Project $project, array $attributes, array $assignees = []): Task
{
    $task = Task::factory()->create(['project_id' => $project->id] + $attributes);

    foreach ($assignees as $member) {
        ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id, 'personal_progress' => 40]);
    }

    return $task;
}

it('shows the people who run the project its tasks and everyone\'s workload on it', function () {
    $first = projectProgressTask($this->project, ['status' => 'in_progress', 'deadline_at' => '2026-10-20'], [$this->worker]);
    $second = projectProgressTask($this->project, ['status' => 'done', 'deadline_at' => '2026-10-10', 'completed_at' => '2026-10-09'], [$this->worker, $this->leaderMember]);
    TaskDependency::create(['task_id' => $first->id, 'depends_on_task_id' => $second->id]);

    // Someone who left the team but still holds one of its tasks stays listed.
    $former = Member::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Deepak Former']);
    projectProgressTask($this->project, ['status' => 'not_started'], [$former]);

    $elsewhere = Project::factory()->create(['organization_id' => $this->organization->id]);
    $elsewhere->teams()->attach($this->team->id);
    projectProgressTask($elsewhere, ['title' => 'Another project\'s work'], [$this->worker]);

    $this->actingAs($this->leader)->get("/workspace/projects/{$this->project->slug}/progress")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Projects/Progress')
            ->where('progress.project.title', 'Ward Upgrade')
            ->has('progress.tasks', 3)
            ->where('progress.tasks', fn ($tasks) => collect($tasks)->firstWhere('id', $first->id)['depends_on'] === [$second->id])
            ->has('progress.members', 4)
            ->where('progress.members.0.name', 'Asha Leader')
            ->where('progress.members.0.role', 'leader')
            ->where('progress.members.1.name', 'Bikash Worker')
            ->where('progress.members.1.role', 'member')
            ->where('progress.members.1.open', 1)
            ->where('progress.members.1.done', 1)
            ->where('progress.members.1.overdue', 1)
            ->where('progress.members.1.progress', 70)
            ->where('progress.members.2.name', 'Chhaya Idle')
            ->where('progress.members.2.progress', null)
            ->where('progress.members.3.name', 'Deepak Former'));
});

it('offers the project\'s progress over the API and a link to it on the project', function () {
    projectProgressTask($this->project, ['status' => 'in_progress'], [$this->worker]);

    Sanctum::actingAs($this->leader, ['*']);
    $this->getJson("/api/v1/workspace/projects/{$this->project->slug}/progress")
        ->assertOk()
        ->assertJsonPath('data.project.slug', $this->project->slug)
        ->assertJsonCount(1, 'data.tasks');

    $this->actingAs($this->leader)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('project.can_view_progress', true));
});

it('opens the project\'s progress to its organization\'s office admin and super-admins', function () {
    $admin = User::factory()->create();
    Member::factory()->linkedTo($admin)->create(['organization_id' => $this->organization->id, 'is_office_admin' => true]);

    $this->actingAs($admin)->get("/workspace/projects/{$this->project->slug}/progress")->assertOk();
    $this->actingAs(User::factory()->create(['email' => 'boss@example.com']))->get("/workspace/projects/{$this->project->slug}/progress")->assertOk();
});

it('keeps the project\'s progress from plain members, other leaders and other organizations', function () {
    $plain = User::factory()->create();
    $this->team->members()->attach(Member::factory()->linkedTo($plain)->create(['organization_id' => $this->organization->id])->id);

    $otherLeader = User::factory()->create();
    $otherTeam = Team::factory()->create(['organization_id' => $this->organization->id]);
    $otherTeam->members()->attach(Member::factory()->linkedTo($otherLeader)->create(['organization_id' => $this->organization->id])->id, ['role' => 'leader']);

    $foreignAdmin = User::factory()->create();
    Member::factory()->linkedTo($foreignAdmin)->create(['organization_id' => Organization::factory()->create()->id, 'is_office_admin' => true]);

    foreach ([$plain, $otherLeader, $foreignAdmin] as $user) {
        $this->actingAs($user)->get("/workspace/projects/{$this->project->slug}/progress")->assertForbidden();
    }

    $this->actingAs($plain)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('project.can_view_progress', false));

    Sanctum::actingAs($plain, ['*']);
    $this->getJson("/api/v1/workspace/projects/{$this->project->slug}/progress")->assertForbidden();
});
