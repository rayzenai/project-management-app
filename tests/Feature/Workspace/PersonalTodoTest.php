<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\PersonalTodo;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;
use App\Queries\MyWorkQuery;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/*
 * Private to-dos ("Just for me"): a person's own items with a title, an
 * optional date and done or not. Only their owner sees or changes them, on
 * any surface; they show in My work and nowhere else, and never notify
 * anyone. "Move to a project" turns one into a task. Today is Wednesday
 * 2026-11-04; without attendance the week runs Sunday to Friday.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-11-04 10:00:00');

    $this->org = Organization::factory()->create(['name' => 'Ministry']);
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
function todoOf(User $user, array $attributes = []): PersonalTodo
{
    return PersonalTodo::forceCreate(['user_id' => $user->id, 'title' => 'Call the office', ...$attributes]);
}

/** @return array<string, list<array<string, mixed>>> My work's rows by group, as `$user` sees them */
function todoGroups(User $user, string $uri = '/workspace/my'): array
{
    $groups = [];

    test()->actingAs($user)->get($uri)->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$groups): void {
            $groups = collect($page->toArray()['props']['groups'])->mapWithKeys(fn (array $group) => [$group['key'] => $group['rows']])->all();
        });

    return $groups;
}

/** @return list<string> every row key on `$user`'s My work */
function todoKeys(User $user, string $uri = '/workspace/my'): array
{
    return collect(todoGroups($user, $uri))->flatten(1)->pluck('key')->all();
}

// ---- Quick add ----

it('adds a private to-do from quick add with a date word', function () {
    Notification::fake();
    $tasks = Task::query()->count();
    $activities = ProjectActivity::query()->count();

    $this->actingAs($this->user)->from('/workspace/my')
        ->post('/workspace/quick-add', ['title' => 'Call the office भोलि', 'personal' => true])
        ->assertRedirect('/workspace/my')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('workspace_flash.message', 'Added to your to-dos.');

    $todo = PersonalTodo::query()->sole();

    expect($todo->user_id)->toBe($this->user->id)
        ->and($todo->title)->toBe('Call the office')
        ->and($todo->due_on?->toDateString())->toBe('2026-11-05')
        ->and($todo->done_at)->toBeNull()
        ->and(Task::query()->count())->toBe($tasks)
        ->and(ProjectActivity::query()->count())->toBe($activities);
    Notification::assertNothingSent();
});

it('drops priority words, and keeps a picked date over a typed one', function () {
    $this->actingAs($this->user)->post('/workspace/quick-add', [
        'title' => 'Renew the licence !high friday',
        'personal' => true,
        'deadline_at' => '2026-11-20',
    ])->assertSessionHasNoErrors();

    $todo = PersonalTodo::query()->sole();

    expect($todo->title)->toBe('Renew the licence')
        ->and($todo->due_on?->toDateString())->toBe('2026-11-20');
});

it('lets a personal add name only the caller', function () {
    $this->actingAs($this->user)->post('/workspace/quick-add', [
        'title' => 'Read the report @bikash',
        'personal' => true,
        'assignee_member_ids' => [$this->member->id],
    ])->assertSessionHasNoErrors();

    // An @word that is nobody stays in the title, as quick add leaves it.
    $this->actingAs($this->user)->post('/workspace/quick-add', ['title' => 'Work @home', 'personal' => true])
        ->assertSessionHasNoErrors();

    expect(PersonalTodo::query()->orderBy('id')->pluck('title')->all())->toBe(['Read the report', 'Work @home']);
});

it('takes an @word for the caller only when it fits nobody else', function () {
    $ramesh = User::factory()->create(['name' => 'Ramesh Adhikari']);
    $rameshMember = Member::factory()->linkedTo($ramesh)->create(['organization_id' => $this->org->id, 'name' => 'Ramesh Adhikari']);
    $ram = Member::factory()->create(['organization_id' => $this->org->id, 'name' => 'Ram Bahadur']);
    $this->project->ensureTeam()->members()->attach([$rameshMember->id, $ram->id]);

    $this->actingAs($ramesh)->post('/workspace/quick-add', ['title' => 'Sign the letter @ram', 'personal' => true])
        ->assertSessionHasErrors(['project_id' => 'Pick a project to give it to someone.']);
    $this->actingAs($ramesh)->post('/workspace/quick-add', ['title' => 'Sign the letter @rames', 'personal' => true])
        ->assertSessionHasNoErrors();

    expect(PersonalTodo::query()->pluck('title')->all())->toBe(['Sign the letter']);
});

it('makes a to-do, never General, when personal comes with start_general', function () {
    $emptyOrg = Organization::factory()->create();
    $admin = User::factory()->create();
    Member::factory()->linkedTo($admin)->create(['organization_id' => $emptyOrg->id, 'is_office_admin' => true]);

    expect(WorkspaceAccess::canStartGeneralProject($admin))->toBeTrue();

    $this->actingAs($admin)->post('/workspace/quick-add', ['title' => 'Plan the week', 'personal' => true, 'start_general' => true])
        ->assertSessionHasNoErrors();

    expect(PersonalTodo::query()->where('user_id', $admin->id)->pluck('title')->all())->toBe(['Plan the week'])
        ->and(Project::query()->where('organization_id', $emptyOrg->id)->exists())->toBeFalse();
});

it('refuses a personal add whose title is only a date word', function () {
    $this->actingAs($this->user)->post('/workspace/quick-add', ['title' => 'भोलि', 'personal' => true])
        ->assertSessionHasErrors(['__global' => 'Title is required.']);

    expect(PersonalTodo::query()->exists())->toBeFalse();
});

it('deletes a person\'s to-dos with their login', function () {
    todoOf($this->user);
    $theirs = todoOf($this->colleagueUser);

    $this->user->delete();

    expect(PersonalTodo::query()->pluck('id')->all())->toBe([$theirs->id]);
});

it('refuses a personal add that names someone else', function (array $fields) {
    $this->actingAs($this->user)->post('/workspace/quick-add', ['personal' => true, ...$fields])
        ->assertSessionHasErrors(['project_id' => 'Pick a project to give it to someone.']);

    expect(PersonalTodo::query()->exists())->toBeFalse()
        ->and(Task::query()->where('title', 'like', 'Collect the forms%')->exists())->toBeFalse();
})->with([
    'an @person' => [['title' => 'Collect the forms @sita']],
    'a picked person' => [fn () => ['title' => 'Collect the forms', 'assignee_member_ids' => [test()->colleague->id]]],
    'the caller and someone else' => [fn () => ['title' => 'Collect the forms', 'assignee_member_ids' => [test()->member->id, test()->colleague->id]]],
]);

it('files it in the project a #token names, which wins over Just for me', function () {
    $this->actingAs($this->user)->post('/workspace/quick-add', ['title' => 'Survey the bridge #roads', 'personal' => true])
        ->assertSessionHasNoErrors();

    $task = Task::query()->where('title', 'Survey the bridge')->sole();

    expect($task->project_id)->toBe($this->project->id)
        ->and($task->assignments()->pluck('member_id')->all())->toBe([$this->member->id])
        ->and(PersonalTodo::query()->exists())->toBeFalse();
});

it('keeps a #token that names no project of theirs in the to-do', function () {
    $hidden = Project::factory()->create(['organization_id' => Organization::factory()->create()->id, 'title' => 'Secret']);
    $hidden->ensureTeam();

    $this->actingAs($this->user)->post('/workspace/quick-add', ['title' => 'Ask about #secret', 'personal' => true])
        ->assertSessionHasNoErrors();

    expect(PersonalTodo::query()->sole()->title)->toBe('Ask about #secret')
        ->and(Task::query()->where('project_id', $hidden->id)->exists())->toBeFalse();
});

it('still asks for a project without Just for me', function () {
    $this->actingAs($this->user)->post('/workspace/quick-add', ['title' => 'Floating'])
        ->assertSessionHasErrors(['project_id' => 'Pick a project.']);

    expect(PersonalTodo::query()->exists())->toBeFalse();
});

// ---- Only the owner ----

it('lets only the owner see or change a to-do', function () {
    $todo = todoOf($this->user, ['due_on' => '2026-11-04']);
    $moveTo = $this->project;

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        $this->actingAs($other)->patch("/workspace/todos/{$todo->id}", ['title' => 'Mine now', 'done' => true])->assertNotFound();
        $this->actingAs($other)->post("/workspace/todos/{$todo->id}/move", ['project_id' => $moveTo->id])->assertNotFound();
        $this->actingAs($other)->delete("/workspace/todos/{$todo->id}")->assertNotFound();

        expect(todoKeys($other))->not->toContain("todo-{$todo->id}")
            ->and(todoKeys($other, '/workspace/my?done=1'))->not->toContain("todo-{$todo->id}");
    }

    $todo->refresh();

    expect($todo->title)->toBe('Call the office')
        ->and($todo->done_at)->toBeNull()
        ->and(Task::query()->where('title', 'Call the office')->exists())->toBeFalse()
        ->and(todoKeys($this->user))->toContain("todo-{$todo->id}");
});

it('decides ownership in one place, for nobody but the owner', function () {
    $todo = todoOf($this->user);

    expect(WorkspaceAccess::ownsPersonalTodo($this->user, $todo))->toBeTrue()
        ->and(WorkspaceAccess::ownsPersonalTodo($this->colleagueUser, $todo))->toBeFalse()
        ->and(WorkspaceAccess::ownsPersonalTodo($this->adminUser, $todo))->toBeFalse()
        ->and(WorkspaceAccess::ownsPersonalTodo($this->boss, $todo))->toBeFalse()
        ->and(WorkspaceAccess::ownsPersonalTodo(null, $todo))->toBeFalse();
});

it('lets the owner rename it, date it and tick it done and back', function () {
    $todo = todoOf($this->user);

    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['title' => 'Call the ministry', 'due_on' => '2026-11-06'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['done' => true])->assertSessionHasNoErrors();

    $todo->refresh();

    expect($todo->title)->toBe('Call the ministry')
        ->and($todo->due_on?->toDateString())->toBe('2026-11-06')
        ->and($todo->done_at?->toIso8601String())->toBe(CarbonImmutable::now()->toIso8601String());

    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['done' => false, 'due_on' => null])->assertSessionHasNoErrors();

    $todo->refresh();

    expect($todo->done_at)->toBeNull()->and($todo->due_on)->toBeNull();

    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['title' => ' '])->assertSessionHasErrors('title');
});

it('lets the owner delete it, quietly for the page that says so itself', function () {
    $todo = todoOf($this->user);

    $this->actingAs($this->user)->withHeader('X-Workspace-Quiet', '1')->delete("/workspace/todos/{$todo->id}")
        ->assertRedirect()->assertSessionMissing('workspace_flash');

    expect(PersonalTodo::query()->exists())->toBeFalse();
});

// ---- Move to a project ----

it('moves a to-do into a project as a task', function () {
    $todo = todoOf($this->user, ['title' => 'Survey the bridge', 'due_on' => '2026-11-10']);

    $response = $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/move", ['project_id' => $this->project->id]);

    $task = Task::query()->where('title', 'Survey the bridge')->sole();

    $response->assertRedirect(route('workspace.tasks.show', ['project' => $this->project->slug, 'task' => $task->slug]))
        ->assertSessionHas('workspace_flash.message', 'Moved to Roads.');

    expect($task->project_id)->toBe($this->project->id)
        ->and($task->deadline_at?->toDateString())->toBe('2026-11-10')
        ->and($task->status)->toBe('not_started')
        ->and($task->created_by)->toBe($this->user->id)
        ->and($task->assignments()->pluck('member_id')->all())->toBe([$this->member->id])
        ->and(PersonalTodo::query()->exists())->toBeFalse();
});

it('moves it unassigned into a project where the caller cannot be assigned', function () {
    $open = Project::factory()->public()->create(['organization_id' => $this->org->id, 'title' => 'Open Data']);
    $open->ensureTeam()->members()->attach($this->colleague->id);
    $todo = todoOf($this->user, ['title' => 'Publish the list']);

    $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/move", ['project_id' => $open->id])
        ->assertRedirect()->assertSessionHasNoErrors();

    $task = Task::query()->where('title', 'Publish the list')->sole();

    expect($task->project_id)->toBe($open->id)
        ->and($task->assignments()->exists())->toBeFalse()
        ->and(PersonalTodo::query()->exists())->toBeFalse();
});

it('refuses a move into a project the caller cannot see, and keeps the to-do', function () {
    $foreign = Project::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    $foreign->ensureTeam();
    $archived = Project::factory()->create(['organization_id' => $this->org->id, 'archived_at' => now()]);
    $archived->ensureTeam()->members()->attach($this->member->id);
    $todo = todoOf($this->user);

    $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/move", ['project_id' => $foreign->id])
        ->assertRedirect()->assertSessionHasErrors(['__global' => 'That project is not available to you.']);
    $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/move", ['project_id' => $archived->id])
        ->assertRedirect()->assertSessionHasErrors(['__global' => 'Pick a project.']);
    $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/move", [])
        ->assertSessionHasErrors(['project_id' => 'Pick a project.']);

    expect(PersonalTodo::query()->whereKey($todo->id)->exists())->toBeTrue()
        ->and(Task::query()->where('title', 'Call the office')->exists())->toBeFalse();
});

// ---- My work ----

it('places open to-dos on My work by their date', function () {
    $today = todoOf($this->user, ['title' => 'Pay the bill', 'due_on' => '2026-11-04']);
    $overdue = todoOf($this->user, ['title' => 'Reply to Hari', 'due_on' => '2026-11-01']);
    $tomorrow = todoOf($this->user, ['title' => 'Book the hall', 'due_on' => '2026-11-05']);
    $undated = todoOf($this->user, ['title' => 'Read the act']);
    todoOf($this->user, ['title' => 'Done already', 'due_on' => '2026-11-04', 'done_at' => '2026-11-03 12:00:00']);
    todoOf($this->colleagueUser, ['title' => 'Not mine', 'due_on' => '2026-11-04']);

    $groups = todoGroups($this->user);

    expect(array_column($groups['today'], 'key'))->toBe(["todo-{$overdue->id}", "todo-{$today->id}"])
        ->and(array_column($groups['tomorrow'], 'key'))->toBe(["todo-{$tomorrow->id}"])
        ->and(array_column($groups['no_date'], 'key'))->toBe(["todo-{$undated->id}"])
        ->and($groups['today'][1])->toBe([
            'kind' => 'todo',
            'key' => "todo-{$today->id}",
            'assignment' => null,
            'task' => null,
            'subtask' => null,
            'todo' => ['id' => $today->id, 'title' => 'Pay the bill', 'due_on' => '2026-11-04', 'started_at' => null, 'done_at' => null],
            'due' => '2026-11-04',
            'days_late' => 0,
            'people' => [],
            'latest_update' => null,
        ]);
});

it('sorts to-dos among the tasks by their date', function () {
    $sooner = Task::factory()->create(['project_id' => $this->project->id, 'status' => 'in_progress', 'deadline_type' => 'rolling', 'deadline_at' => '2026-11-09']);
    ProjectAssignment::create(['task_id' => $sooner->id, 'member_id' => $this->member->id]);
    $todo = todoOf($this->user, ['due_on' => '2026-11-10']);
    $later = Task::factory()->create(['project_id' => $this->project->id, 'status' => 'in_progress', 'deadline_type' => 'rolling', 'deadline_at' => '2026-11-12']);
    ProjectAssignment::create(['task_id' => $later->id, 'member_id' => $this->member->id]);

    expect(array_column(todoGroups($this->user)['coming_up'], 'key'))->toBe(["task-{$sooner->id}", "todo-{$todo->id}", "task-{$later->id}"]);
});

it('shows to-dos done in the last 7 days only with Show done, among the done tasks', function () {
    todoOf($this->user, ['title' => 'Still open']);
    $yesterday = todoOf($this->user, ['done_at' => '2026-11-03 09:00:00']);
    todoOf($this->user, ['done_at' => '2026-10-26 09:00:00']);
    $task = Task::factory()->create(['project_id' => $this->project->id, 'status' => 'done', 'completed_at' => '2026-11-03 15:00:00']);
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $this->member->id]);

    expect(todoGroups($this->user))->not->toHaveKey('done');

    $done = todoGroups($this->user, '/workspace/my?done=1')['done'];

    expect(array_column($done, 'key'))->toBe(["task-{$task->id}", "todo-{$yesterday->id}"])
        ->and($done[1]['todo']['done_at'])->toBe(CarbonImmutable::parse('2026-11-03 09:00:00')->toIso8601String());
});

it('keeps to-dos whatever organization a super-admin switched to', function () {
    Member::factory()->linkedTo($this->boss)->create(['organization_id' => null]);
    $this->boss->forceFill(['workspace_organization_id' => $this->org->id])->save();
    $todo = todoOf($this->boss, ['due_on' => '2026-11-04']);

    expect(todoKeys($this->boss))->toContain("todo-{$todo->id}");
});

it('reads to-dos in one query however many there are', function () {
    $count = function (int $todos): int {
        // range(1, 0) would be [1, 0]: none means none.
        foreach ($todos === 0 ? [] : range(1, $todos) as $i) {
            todoOf($this->user, ['title' => "Item {$i}", 'due_on' => $i % 2 === 0 ? '2026-11-05' : null]);
            todoOf($this->user, ['title' => "Done {$i}", 'done_at' => '2026-11-03 09:00:00']);
        }

        WorkspaceAccess::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(MyWorkQuery::class)->get($this->user->fresh(), null, true);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $none = $count(0);
    $one = $count(1);
    PersonalTodo::query()->delete();

    expect(PersonalTodo::query()->count())->toBe(0)
        ->and($one)->toBe($count(4))
        ->and($one)->toBe($none);
});

// ---- Where quick add starts ----

it('starts quick add on Just for me for someone with no project and no General to start', function () {
    $loner = User::factory()->create();
    Member::factory()->linkedTo($loner)->create(['organization_id' => Organization::factory()->create()->id]);
    $emptyAdmin = User::factory()->create();
    Member::factory()->linkedTo($emptyAdmin)->create(['organization_id' => Organization::factory()->create()->id, 'is_office_admin' => true]);

    $startsPersonal = function (User $user): bool {
        $value = null;

        $this->actingAs($user)->get('/workspace/my')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$value): void {
                $value = $page->toArray()['props']['quickAddContext']['startPersonal'];
            });

        return $value;
    };

    expect($startsPersonal($loner))->toBeTrue()
        ->and($startsPersonal($emptyAdmin))->toBeFalse()
        ->and($startsPersonal($this->user))->toBeFalse();
});
