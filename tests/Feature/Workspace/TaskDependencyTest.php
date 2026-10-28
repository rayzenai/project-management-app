<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\Team;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);

    $this->user = User::factory()->withMember()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach(Member::query()->where('user_id', $this->user->id)->value('id'), ['role' => 'leader']);
    $this->project = Project::factory()->create();
    $this->project->teams()->attach($this->team->id);

    [$this->a, $this->b, $this->c] = collect(['Survey', 'Design', 'Build'])
        ->map(fn (string $title) => Task::factory()->create(['project_id' => $this->project->id, 'title' => $title]))
        ->all();
});

function dependOn(Task $task, Task $on): void
{
    TaskDependency::create(['task_id' => $task->id, 'depends_on_task_id' => $on->id]);
}

it('records that a task depends on another and logs it', function () {
    $this->actingAs($this->user)
        ->post("/workspace/tasks/{$this->b->id}/dependencies", ['depends_on_task_id' => $this->a->id])
        ->assertSessionHasNoErrors();

    expect($this->b->dependencies()->pluck('tasks.id')->all())->toBe([$this->a->id])
        ->and($this->a->dependents()->pluck('tasks.id')->all())->toBe([$this->b->id])
        ->and(ProjectActivity::query()->where('task_id', $this->b->id)->latest('id')->value('description'))
        ->toBe('Now depends on "Survey"');
});

it('removes a dependency and logs it', function () {
    dependOn($this->b, $this->a);

    $this->actingAs($this->user)
        ->delete("/workspace/tasks/{$this->b->id}/dependencies/{$this->a->id}")
        ->assertSessionHasNoErrors();

    expect($this->b->dependencies()->exists())->toBeFalse()
        ->and(ProjectActivity::query()->where('task_id', $this->b->id)->latest('id')->value('description'))
        ->toBe('No longer depends on "Survey"');
});

it('refuses a task depending on itself, a duplicate, or another project\'s task', function () {
    dependOn($this->b, $this->a);
    $elsewhere = Task::factory()->create(['project_id' => Project::factory()->create()->id]);
    $this->actingAs($this->user)->from("/workspace/projects/{$this->project->slug}");

    foreach ([$this->b->id, $this->a->id, $elsewhere->id] as $id) {
        $this->post("/workspace/tasks/{$this->b->id}/dependencies", ['depends_on_task_id' => $id])
            ->assertSessionHasErrors('depends_on_task_id');
    }

    expect($this->b->dependencies()->pluck('tasks.id')->all())->toBe([$this->a->id]);
});

it('refuses loops, direct or through other tasks', function () {
    dependOn($this->b, $this->a);
    dependOn($this->c, $this->b);
    $this->actingAs($this->user)->from("/workspace/projects/{$this->project->slug}");

    $this->post("/workspace/tasks/{$this->a->id}/dependencies", ['depends_on_task_id' => $this->b->id])
        ->assertSessionHasErrors('depends_on_task_id');
    $this->post("/workspace/tasks/{$this->a->id}/dependencies", ['depends_on_task_id' => $this->c->id])
        ->assertSessionHasErrors('depends_on_task_id');

    expect($this->a->dependencies()->exists())->toBeFalse();
});

it('keeps people who cannot see the project out', function () {
    $outsider = User::factory()->create();
    Member::factory()->linkedTo($outsider)->create(['organization_id' => Organization::factory()->create()->id]);

    $this->actingAs($outsider)
        ->post("/workspace/tasks/{$this->b->id}/dependencies", ['depends_on_task_id' => $this->a->id])
        ->assertForbidden();

    expect($this->b->dependencies()->exists())->toBeFalse();
});

it('offers the same over the API, answering with the task\'s links', function () {
    Sanctum::actingAs($this->user, ['*']);

    $this->postJson("/api/v1/workspace/tasks/{$this->b->id}/dependencies", ['depends_on_task_id' => $this->a->id])
        ->assertCreated()
        ->assertJsonPath('data.dependencies.0.id', $this->a->id)
        ->assertJsonPath('data.dependencies.0.title', 'Survey');

    $this->postJson("/api/v1/workspace/tasks/{$this->a->id}/dependencies", ['depends_on_task_id' => $this->b->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('depends_on_task_id');

    $this->deleteJson("/api/v1/workspace/tasks/{$this->b->id}/dependencies/{$this->a->id}")
        ->assertOk()
        ->assertJsonPath('data.dependencies', []);
});

it('hands the dependencies to the Gantt and the task page', function () {
    dependOn($this->b, $this->a);
    dependOn($this->c, $this->b);

    $this->actingAs($this->user)->get("/workspace/teams/{$this->team->id}/progress")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('progress.tasks', fn ($tasks) => collect($tasks)->mapWithKeys(fn ($t) => [$t['title'] => $t['depends_on']])->all() === [
                'Survey' => [],
                'Design' => [$this->a->id],
                'Build' => [$this->b->id],
            ]));

    $this->actingAs($this->user)->get("/workspace/projects/{$this->project->slug}/tasks/{$this->b->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('task.dependencies.0.title', 'Survey')
            ->where('task.dependents.0.title', 'Build')
            ->has('dependencyCandidates', 2));
});

it('creates a task that already waits for others, ignoring tasks of another project', function () {
    $elsewhere = Task::factory()->create(['project_id' => Project::factory()->create()->id]);

    $this->actingAs($this->user)->post('/workspace/quick-add', [
        'project_id' => $this->project->id,
        'title' => 'Launch',
        'depends_on_task_ids' => [$this->b->id, $this->c->id, $elsewhere->id],
    ])->assertSessionHasNoErrors();

    $launch = Task::query()->where('title', 'Launch')->firstOrFail();

    expect($launch->dependencies()->pluck('tasks.id')->sort()->values()->all())->toBe([$this->b->id, $this->c->id]);
});

it('gives the board and the task preview what each task waits for and holds up', function () {
    dependOn($this->b, $this->a);

    $this->actingAs($this->user)->get("/workspace/projects/{$this->project->slug}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tasks', fn ($tasks) => collect($tasks)->firstWhere('title', 'Design')['dependencies'][0]['title'] === 'Survey'
                && collect($tasks)->firstWhere('title', 'Survey')['dependents'][0]['title'] === 'Design'));

    $this->actingAs($this->user)->getJson("/workspace/tasks/{$this->b->id}/preview")
        ->assertOk()
        ->assertJsonPath('data.task.dependencies.0.title', 'Survey')
        ->assertJsonCount(2, 'data.dependency_candidates');
});
