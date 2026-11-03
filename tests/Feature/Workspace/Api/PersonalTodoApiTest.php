<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\PersonalTodo;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

/*
 * The app's private to-dos: quick add's `personal`, and PATCH, DELETE and
 * move under /api/v1/workspace/todos, each its owner's alone. Today is
 * Wednesday 2026-11-04.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-11-04 10:00:00');

    $this->org = Organization::factory()->create();
    $this->project = Project::factory()->create(['organization_id' => $this->org->id, 'key' => 'RD', 'title' => 'Roads']);

    $this->user = User::factory()->create(['name' => 'Bikash Karki']);
    $this->member = Member::factory()->linkedTo($this->user)->create(['organization_id' => $this->org->id, 'name' => 'Bikash Karki']);
    $this->colleagueUser = User::factory()->create(['name' => 'Sita Sharma']);
    $this->colleague = Member::factory()->linkedTo($this->colleagueUser)->create(['organization_id' => $this->org->id, 'name' => 'Sita Sharma']);
    $this->project->ensureTeam()->members()->attach([$this->member->id, $this->colleague->id]);

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);
    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param  array<string, mixed>  $attributes */
function apiTodoOf(User $user, array $attributes = []): PersonalTodo
{
    return PersonalTodo::forceCreate(['user_id' => $user->id, 'title' => 'Call the office', ...$attributes]);
}

/** @return list<string> every row key of `$user`'s My work, done included */
function apiMyWorkKeys(User $user): array
{
    Sanctum::actingAs($user);

    return collect(test()->getJson('/api/v1/workspace/my-work?done=1')->assertOk()->json('groups'))
        ->flatMap(fn (array $group) => array_column($group['rows'], 'key'))
        ->all();
}

it('adds a private to-do from quick add with a date word', function () {
    Notification::fake();
    $tasks = Task::query()->count();
    $activities = ProjectActivity::query()->count();
    Sanctum::actingAs($this->user);

    $json = $this->postJson('/api/v1/workspace/quick-add', ['title' => 'Call the office भोलि !urgent', 'personal' => true])
        ->assertCreated()
        ->assertJsonPath('message', 'Added to your to-dos.')
        ->json('data');

    $todo = PersonalTodo::query()->sole();

    expect($json)->toEqual([
        'id' => $todo->id,
        'title' => 'Call the office',
        'due_on' => '2026-11-05',
        'done_at' => null,
        'created_at' => CarbonImmutable::now()->toIso8601String(),
    ])->and($todo->user_id)->toBe($this->user->id)
        ->and(Task::query()->count())->toBe($tasks)
        ->and(ProjectActivity::query()->count())->toBe($activities);
    Notification::assertNothingSent();
});

it('refuses a personal add that names someone else', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/workspace/quick-add', ['title' => 'Collect the forms @sita', 'personal' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_id' => 'Pick a project to give it to someone.']);
    $this->postJson('/api/v1/workspace/quick-add', [
        'title' => 'Collect the forms',
        'personal' => true,
        'assignee_member_ids' => [$this->colleague->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['project_id' => 'Pick a project to give it to someone.']);

    expect(PersonalTodo::query()->exists())->toBeFalse()
        ->and(Task::query()->where('title', 'like', 'Collect the forms%')->exists())->toBeFalse();
});

it('refuses a personal add whose title is only a date word', function () {
    Sanctum::actingAs($this->user);

    $this->postJson('/api/v1/workspace/quick-add', ['title' => 'भोलि !high', 'personal' => true])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Title is required.');

    expect(PersonalTodo::query()->exists())->toBeFalse();
});

it('lets only the owner see or change a to-do', function () {
    $todo = apiTodoOf($this->user, ['due_on' => '2026-11-04']);
    $done = apiTodoOf($this->user, ['title' => 'Done one', 'done_at' => '2026-11-03 09:00:00']);

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        Sanctum::actingAs($other);

        $this->patchJson("/api/v1/workspace/todos/{$todo->id}", ['title' => 'Mine now', 'done' => true])->assertNotFound();
        $this->postJson("/api/v1/workspace/todos/{$todo->id}/move", ['project_id' => $this->project->id])->assertNotFound();
        $this->deleteJson("/api/v1/workspace/todos/{$todo->id}")->assertNotFound();

        expect(apiMyWorkKeys($other))->not->toContain("todo-{$todo->id}")->not->toContain("todo-{$done->id}");
    }

    $todo->refresh();

    expect($todo->title)->toBe('Call the office')
        ->and($todo->done_at)->toBeNull()
        ->and(apiMyWorkKeys($this->user))->toContain("todo-{$todo->id}", "todo-{$done->id}");
});

it('answers another person\'s to-do exactly as one that does not exist', function () {
    $todo = apiTodoOf($this->user);
    $gone = apiTodoOf($this->user);
    $goneId = $gone->id;
    $gone->delete();
    Sanctum::actingAs($this->colleagueUser);

    $theirs = $this->patchJson("/api/v1/workspace/todos/{$todo->id}", ['done' => true])->assertNotFound()->json('message');
    $missing = $this->patchJson("/api/v1/workspace/todos/{$goneId}", ['done' => true])->assertNotFound()->json('message');

    expect(str_replace((string) $todo->id, '{id}', $theirs))->toBe(str_replace((string) $goneId, '{id}', $missing));
});

it('lets the owner change, tick and delete a to-do', function () {
    $todo = apiTodoOf($this->user);
    Sanctum::actingAs($this->user);

    $this->patchJson("/api/v1/workspace/todos/{$todo->id}", ['title' => 'Call the ministry', 'due_on' => '2026-11-06', 'done' => true])
        ->assertOk()
        ->assertJsonPath('data.title', 'Call the ministry')
        ->assertJsonPath('data.due_on', '2026-11-06')
        ->assertJsonPath('data.done_at', CarbonImmutable::now()->toIso8601String());

    $this->deleteJson("/api/v1/workspace/todos/{$todo->id}")->assertOk()->assertJsonPath('message', 'To-do deleted.');

    expect(PersonalTodo::query()->exists())->toBeFalse();
});

it('moves a to-do into a project as a task', function () {
    $todo = apiTodoOf($this->user, ['title' => 'Survey the bridge', 'due_on' => '2026-11-10']);
    Sanctum::actingAs($this->user);

    $this->postJson("/api/v1/workspace/todos/{$todo->id}/move", ['project_id' => $this->project->id])
        ->assertCreated()
        ->assertJsonPath('message', 'Moved to Roads.')
        ->assertJsonPath('data.title', 'Survey the bridge')
        ->assertJsonPath('data.deadline_at', fn (?string $at) => str_starts_with((string) $at, '2026-11-10'));

    $task = Task::query()->where('title', 'Survey the bridge')->sole();

    expect($task->assignments()->pluck('member_id')->all())->toBe([$this->member->id])
        ->and(PersonalTodo::query()->exists())->toBeFalse();
});

it('refuses a move into a project the caller cannot see, and keeps the to-do', function () {
    $foreign = Project::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $foreign->ensureTeam();
    $todo = apiTodoOf($this->user);
    Sanctum::actingAs($this->user);

    $this->postJson("/api/v1/workspace/todos/{$todo->id}/move", ['project_id' => $foreign->id])
        ->assertForbidden()
        ->assertJsonPath('message', 'That project is not available to you.');

    expect(PersonalTodo::query()->whereKey($todo->id)->exists())->toBeTrue()
        ->and(Task::query()->where('project_id', $foreign->id)->exists())->toBeFalse();
});

it('lists open to-dos as rows of My work', function () {
    $todo = apiTodoOf($this->user, ['due_on' => '2026-11-04']);
    Sanctum::actingAs($this->user);

    $today = collect($this->getJson('/api/v1/workspace/my-work')->assertOk()->json('groups'))->firstWhere('key', 'today');

    expect($today['rows'])->toEqual([[
        'kind' => 'todo',
        'key' => "todo-{$todo->id}",
        'assignment' => null,
        'task' => null,
        'subtask' => null,
        'todo' => ['id' => $todo->id, 'title' => 'Call the office', 'due_on' => '2026-11-04', 'done_at' => null],
        'due' => '2026-11-04',
        'days_late' => 0,
        'people' => [],
        'latest_update' => null,
    ]]);
});
