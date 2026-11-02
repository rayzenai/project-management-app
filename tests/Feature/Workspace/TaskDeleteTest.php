<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

/*
 * Who may delete a task (to the trash, undoable) and restore it: whoever
 * runs its project, or the person who created it. Not a teammate, not a
 * viewer of a public project.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->org = Organization::factory()->create();
    $this->project = Project::factory()->public()->create(['organization_id' => $this->org->id]);
    $team = $this->project->ensureTeam();
    $this->manager = taskDeletePerson($this->org, $team, 'leader');
    $this->author = taskDeletePerson($this->org, $team, 'member');
    $this->teammate = taskDeletePerson($this->org, $team, 'member');
    // Same organization, not on the team: sees the public project.
    $this->viewer = taskDeletePerson($this->org);
    $this->task = Task::factory()->for($this->project)->create(['created_by' => $this->author->id]);
    $this->url = "/workspace/projects/{$this->project->slug}/tasks/{$this->task->slug}";
});

function taskDeletePerson(Organization $organization, ?Team $team = null, ?string $role = null, bool $officeAdmin = false): User
{
    $member = Member::factory()->withUser()->create(['organization_id' => $organization->id, 'is_office_admin' => $officeAdmin]);

    if ($team !== null) {
        $team->members()->attach($member->id, ['role' => $role]);
    }

    return $member->user;
}

it('records who created a task, never whoever the request names', function () {
    $this->actingAs($this->author)
        ->post("/workspace/projects/{$this->project->slug}/tasks", ['title' => 'From the web', 'created_by' => $this->manager->id])
        ->assertRedirect();
    $this->actingAs($this->author)
        ->post('/workspace/quick-add', ['project_id' => $this->project->id, 'title' => 'From quick add', 'created_by' => $this->manager->id])
        ->assertRedirect();
    Sanctum::actingAs($this->teammate, ['*']);
    $this->postJson("/api/v1/workspace/projects/{$this->project->slug}/tasks", ['title' => 'From the app', 'created_by' => $this->manager->id])
        ->assertCreated();

    expect(Task::query()->whereIn('title', ['From the web', 'From quick add', 'From the app'])->orderBy('title')->pluck('created_by', 'title')->all())
        ->toBe(['From quick add' => $this->author->id, 'From the app' => $this->teammate->id, 'From the web' => $this->author->id]);
});

it('lets the creator delete their task, back to the page they were on, and undo it', function () {
    $this->actingAs($this->author)
        ->from("/workspace/projects/{$this->project->slug}?view=board")
        ->delete($this->url)
        ->assertRedirect("/workspace/projects/{$this->project->slug}?view=board")
        ->assertSessionHas('workspace_flash.undo.url', route('workspace.tasks.restore', [$this->project, $this->task]));

    expect($this->task->fresh()->trashed())->toBeTrue();

    $this->actingAs($this->author)->post("{$this->url}/restore")->assertRedirect();

    expect($this->task->fresh()->trashed())->toBeFalse();
});

it('goes to the project when the task is deleted from its own page', function () {
    $this->actingAs($this->author)
        ->from($this->url)
        ->delete($this->url)
        ->assertRedirect(route('workspace.projects.show', ['project' => $this->project->slug]));
});

it('lets whoever runs the project delete anyone\'s task', function () {
    $programManager = Member::factory()->withUser()->create(['organization_id' => $this->org->id]);
    $program = Program::factory()->create(['organization_id' => $this->org->id, 'manager_id' => $programManager->id]);
    $this->project->forceFill(['program_id' => $program->id])->save();
    $officeAdmin = taskDeletePerson($this->org, officeAdmin: true);
    $boss = User::factory()->create(['email' => 'boss@example.com']);

    foreach ([$this->manager, $programManager->user, $officeAdmin, $boss] as $runner) {
        $this->actingAs($runner)->delete($this->url)->assertRedirect();
        expect($this->task->fresh()->trashed())->toBeTrue();

        $this->actingAs($runner)->post("{$this->url}/restore")->assertRedirect();
        expect($this->task->fresh()->trashed())->toBeFalse();
    }
});

it('refuses a teammate and a viewer of the public project, on the web and in the app', function () {
    foreach ([$this->teammate, $this->viewer] as $other) {
        $this->actingAs($other)->delete($this->url)->assertForbidden();

        Sanctum::actingAs($other, ['*']);
        $this->deleteJson("/api/v1{$this->url}")->assertForbidden();
    }

    expect($this->task->fresh()->trashed())->toBeFalse();

    // Nor bring back a task its creator deleted.
    $this->task->delete();
    $this->actingAs($this->teammate)->post("{$this->url}/restore")->assertForbidden();
    Sanctum::actingAs($this->teammate, ['*']);
    $this->postJson("/api/v1{$this->url}/restore")->assertForbidden();

    expect($this->task->fresh()->trashed())->toBeTrue();
});

it('stops a creator who can no longer see the project', function () {
    $this->project->forceFill(['is_public' => false])->save();
    $this->project->ensureTeam()->members()->detach(Member::query()->where('user_id', $this->author->id)->value('id'));

    $this->actingAs($this->author)->delete($this->url)->assertForbidden();

    expect($this->task->fresh()->trashed())->toBeFalse();
});

it('tells the task page and the slide-over who may delete', function () {
    $this->actingAs($this->author)->get($this->url)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canDelete', true));
    $this->actingAs($this->teammate)->get($this->url)
        ->assertInertia(fn (AssertableInertia $page) => $page->where('canDelete', false));

    $this->actingAs($this->manager)->getJson("/workspace/tasks/{$this->task->id}/preview")
        ->assertOk()->assertJsonPath('data.can_delete', true);
    $this->actingAs($this->teammate)->getJson("/workspace/tasks/{$this->task->id}/preview")
        ->assertOk()->assertJsonPath('data.can_delete', false);

    Sanctum::actingAs($this->author, ['*']);
    $this->getJson("/api/v1/workspace/tasks/{$this->task->id}/preview")->assertOk()->assertJsonPath('data.can_delete', true);
});

it('fills in the creator of existing tasks from their activity', function () {
    $this->actingAs($this->author);
    $logged = Task::factory()->for($this->project)->create();
    auth()->logout();
    $unlogged = Task::factory()->for($this->project)->create();
    DB::table('tasks')->update(['created_by' => null]);

    (require database_path('migrations/2026_11_02_100000_add_created_by_to_tasks.php'))->backfill();

    expect($logged->fresh()->created_by)->toBe($this->author->id)
        ->and($unlogged->fresh()->created_by)->toBeNull();
});
