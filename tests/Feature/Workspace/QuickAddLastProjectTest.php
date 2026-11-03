<?php

use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * The add field starts on the project the person last added a task to, or
 * was last added to, whichever is newer (`quickAddContext.lastProjectId`),
 * among the projects it offers them: ones they can see that are not
 * archived.
 */

beforeEach(function () {
    $this->user = User::factory()->withMember()->create();
    $this->member = Member::query()->where('user_id', $this->user->id)->sole();
    $this->lastProjectIs = fn (?int $id) => $this->actingAs($this->user)->get('/workspace/my')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('quickAddContext.lastProjectId', $id));
});

it('is the project of the newest task you created', function () {
    $older = Project::factory()->public()->create(['title' => 'Older']);
    $newer = Project::factory()->public()->create(['title' => 'Newer']);
    $theirs = Project::factory()->public()->create(['title' => 'Theirs']);

    Task::factory()->for($newer)->create(['created_by' => $this->user->id, 'created_at' => now()->subDay()]);
    Task::factory()->for($older)->create(['created_by' => $this->user->id, 'created_at' => now()->subDays(3)]);
    // Newer still, but someone else's task, or nobody's.
    Task::factory()->for($theirs)->create(['created_by' => User::factory()->create()->id]);
    Task::factory()->for($theirs)->create(['created_by' => null]);

    ($this->lastProjectIs)($newer->id);
});

it('is null when you have created no task', function () {
    $project = Project::factory()->public()->create();
    Task::factory()->for($project)->create(['created_by' => User::factory()->create()->id]);

    ($this->lastProjectIs)(null);
});

it('is never a project you can no longer see or that was archived', function () {
    $open = Project::factory()->public()->create(['title' => 'Open']);
    $private = Project::factory()->create(['title' => 'Private', 'is_public' => false]);
    $private->ensureTeam()->members()->attach($this->member->id, ['role' => 'member']);

    Task::factory()->for($open)->create(['created_by' => $this->user->id, 'created_at' => now()->subDays(2)]);
    Task::factory()->for($private)->create(['created_by' => $this->user->id, 'created_at' => now()->subDay()]);

    ($this->lastProjectIs)($private->id);

    // Taken off its team: the private project is gone from the picker.
    $private->ensureTeam()->members()->detach($this->member->id);

    ($this->lastProjectIs)($open->id);

    $open->forceFill(['archived_at' => now()])->save();

    ($this->lastProjectIs)(null);
});

it('is the project you were just added to when you have created no task', function () {
    $project = Project::factory()->create(['title' => 'Office Automation System']);
    $this->travelTo(now()->subDay(), fn () => $project->ensureTeam()->members()->attach($this->member->id, ['role' => 'member']));

    ($this->lastProjectIs)($project->id);
});

it('is the newer of the task you created and the team you joined', function () {
    $joined = Project::factory()->create(['title' => 'Joined']);
    $elsewhere = Project::factory()->public()->create(['title' => 'Elsewhere']);

    $this->travelTo(now()->subDays(3), fn () => $joined->ensureTeam()->members()->attach($this->member->id, ['role' => 'member']));
    $task = Task::factory()->for($elsewhere)->create(['created_by' => $this->user->id, 'created_at' => now()->subDay()]);

    ($this->lastProjectIs)($elsewhere->id);

    $task->forceFill(['created_at' => now()->subDays(5)])->save();

    ($this->lastProjectIs)($joined->id);
});

it('never takes a membership with no date', function () {
    $made = Project::factory()->public()->create(['title' => 'Made']);
    $undated = Project::factory()->create(['title' => 'Undated']);
    $dated = Project::factory()->create(['title' => 'Dated']);

    Task::factory()->for($made)->create(['created_by' => $this->user->id, 'created_at' => now()->subDays(2)]);
    $undated->ensureTeam()->members()->attach($this->member->id, ['role' => 'member']);
    DB::table('member_team')->where('team_id', $undated->ensureTeam()->id)->update(['created_at' => null]);

    ($this->lastProjectIs)($made->id);

    Task::query()->where('project_id', $made->id)->forceDelete();
    $this->travelTo(now()->subDays(3), fn () => $dated->ensureTeam()->members()->attach($this->member->id, ['role' => 'member']));

    ($this->lastProjectIs)($dated->id);
});
