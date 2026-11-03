<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\PersonalTodo;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Queries\PersonalTodosQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * The top bar's to-do list: PersonalTodosQuery's three lists (Doing, To do,
 * the last week's Done), "doing" (`started_at`), the count beside the
 * button, deleting with Undo (a soft delete, restored by its owner, pruned
 * after 7 days), and the key quick add flashes so My work can show the new
 * row. Every read and change is the owner's alone. Today is Wednesday
 * 2026-11-04.
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
function listTodo(User $user, array $attributes = []): PersonalTodo
{
    return PersonalTodo::forceCreate(['user_id' => $user->id, 'title' => 'Call the office', ...$attributes]);
}

function iso(string $at): string
{
    return CarbonImmutable::parse($at)->toIso8601String();
}

// ---- The lists ----

it('lists doing, to do and the last week\'s done, each in its order', function () {
    listTodo($this->user, ['title' => 'No date, newer', 'created_at' => '2026-11-03 09:00:00']);
    listTodo($this->user, ['title' => 'No date, older', 'created_at' => '2026-11-01 09:00:00']);
    listTodo($this->user, ['title' => 'Friday', 'due_on' => '2026-11-06']);
    listTodo($this->user, ['title' => 'Monday', 'due_on' => '2026-11-02']);
    listTodo($this->user, ['title' => 'Started today', 'started_at' => '2026-11-04 09:00:00']);
    $started = listTodo($this->user, ['title' => 'Started Monday', 'started_at' => '2026-11-02 09:00:00', 'due_on' => '2026-11-05']);
    $done = listTodo($this->user, ['title' => 'Done after starting', 'started_at' => '2026-11-03 08:00:00', 'done_at' => '2026-11-03 17:00:00']);
    listTodo($this->user, ['title' => 'Done today', 'done_at' => '2026-11-04 08:00:00']);
    listTodo($this->user, ['title' => 'Done 7 days back', 'done_at' => '2026-10-28 08:00:00']);
    listTodo($this->user, ['title' => 'Done 8 days back', 'done_at' => '2026-10-27 23:00:00']);
    listTodo($this->user, ['title' => 'Deleted'])->delete();
    listTodo($this->user, ['title' => 'Deleted while doing', 'started_at' => '2026-11-01 09:00:00'])->delete();
    listTodo($this->colleagueUser, ['title' => 'Not mine']);
    listTodo($this->colleagueUser, ['title' => 'Not mine either', 'started_at' => '2026-11-01 09:00:00']);

    $lists = PersonalTodosQuery::for($this->user);

    expect(array_column($lists['doing'], 'title'))->toBe(['Started Monday', 'Started today'])
        ->and(array_column($lists['open'], 'title'))->toBe(['Monday', 'Friday', 'No date, older', 'No date, newer'])
        ->and(array_column($lists['done'], 'title'))->toBe(['Done today', 'Done after starting', 'Done 7 days back'])
        ->and($lists['doing'][0])->toEqual([
            'id' => $started->id,
            'title' => 'Started Monday',
            'due_on' => '2026-11-05',
            'started_at' => iso('2026-11-02 09:00:00'),
            'done_at' => null,
        ])
        ->and($lists['done'][1])->toEqual([
            'id' => $done->id,
            'title' => 'Done after starting',
            'due_on' => null,
            'started_at' => iso('2026-11-03 08:00:00'),
            'done_at' => iso('2026-11-03 17:00:00'),
        ]);
});

it('keeps the 20 newest done to-dos', function () {
    foreach (range(1, 22) as $minute) {
        listTodo($this->user, ['title' => "Done {$minute}", 'done_at' => CarbonImmutable::parse('2026-11-04 08:00:00')->addMinutes($minute)]);
    }

    $done = PersonalTodosQuery::for($this->user)['done'];

    expect($done)->toHaveCount(20)
        ->and($done[0]['title'])->toBe('Done 22')
        ->and($done[19]['title'])->toBe('Done 3');
});

it('reads the lists in one query', function () {
    listTodo($this->user, ['started_at' => '2026-11-03 09:00:00']);
    listTodo($this->user);
    listTodo($this->user, ['done_at' => '2026-11-03 09:00:00']);

    DB::enableQueryLog();
    PersonalTodosQuery::for($this->user);

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('answers the list as JSON to its owner only', function () {
    $mine = listTodo($this->user, ['title' => 'Mine', 'due_on' => '2026-11-05']);
    listTodo($this->colleagueUser, ['title' => 'Sita\'s']);

    $this->actingAs($this->user)->getJson('/workspace/todos')
        ->assertOk()
        ->assertExactJson([
            'today' => '2026-11-04',
            'doing' => [],
            'open' => [['id' => $mine->id, 'title' => 'Mine', 'due_on' => '2026-11-05', 'started_at' => null, 'done_at' => null]],
            'done' => [],
        ]);

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        expect(collect($this->actingAs($other)->getJson('/workspace/todos')->assertOk()->json('open'))->pluck('id'))
            ->not->toContain($mine->id);
    }
});

it('asks a guest to sign in for the list', function () {
    $this->getJson('/workspace/todos')->assertUnauthorized();
    $this->get('/workspace/todos')->assertRedirect('/workspace/login');
});

// ---- Doing ----

it('starts and stops a to-do, and done keeps when it was started', function () {
    $todo = listTodo($this->user);

    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['doing' => true])->assertSessionHasNoErrors();
    expect($todo->refresh()->started_at?->toIso8601String())->toBe(iso('2026-11-04 10:00:00'));

    // Starting again keeps the first start.
    CarbonImmutable::setTestNow('2026-11-04 11:00:00');
    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['doing' => true])->assertSessionHasNoErrors();
    expect($todo->refresh()->started_at?->toIso8601String())->toBe(iso('2026-11-04 10:00:00'));

    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['done' => true])->assertSessionHasNoErrors();
    $todo->refresh();
    expect($todo->done_at?->toIso8601String())->toBe(iso('2026-11-04 11:00:00'))
        ->and($todo->started_at?->toIso8601String())->toBe(iso('2026-11-04 10:00:00'));

    // Reopened, it is doing again: only done_at goes.
    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['done' => false])->assertSessionHasNoErrors();
    $todo->refresh();
    expect($todo->done_at)->toBeNull()
        ->and($todo->started_at?->toIso8601String())->toBe(iso('2026-11-04 10:00:00'))
        ->and(array_column(PersonalTodosQuery::for($this->user)['doing'], 'id'))->toBe([$todo->id]);

    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['doing' => false])->assertSessionHasNoErrors();
    expect($todo->refresh()->started_at)->toBeNull()
        ->and(array_column(PersonalTodosQuery::for($this->user)['open'], 'id'))->toBe([$todo->id]);
});

it('refuses a doing that is not a yes or no', function () {
    $todo = listTodo($this->user);

    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['doing' => 'soon'])->assertSessionHasErrors('doing');

    expect($todo->refresh()->started_at)->toBeNull();
});

it('lets nobody but the owner start or stop a to-do', function () {
    $todo = listTodo($this->user);
    $started = listTodo($this->user, ['started_at' => '2026-11-03 09:00:00']);

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        $this->actingAs($other)->patch("/workspace/todos/{$todo->id}", ['doing' => true])->assertNotFound();
        $this->actingAs($other)->patch("/workspace/todos/{$started->id}", ['doing' => false])->assertNotFound();
    }

    expect($todo->refresh()->started_at)->toBeNull()
        ->and($started->refresh()->started_at?->toIso8601String())->toBe(iso('2026-11-03 09:00:00'));
});

it('shows doing on My work\'s to-do rows', function () {
    $todo = listTodo($this->user, ['due_on' => '2026-11-04', 'started_at' => '2026-11-03 09:00:00']);

    $this->actingAs($this->user)->get('/workspace/my')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('groups.0.key', 'today')
            ->where('groups.0.rows.0.todo', [
                'id' => $todo->id,
                'title' => 'Call the office',
                'due_on' => '2026-11-04',
                'started_at' => iso('2026-11-03 09:00:00'),
                'done_at' => null,
            ]));
});

// ---- The count ----

it('counts the owner\'s open and doing to-dos for the top bar', function () {
    listTodo($this->user);
    listTodo($this->user, ['started_at' => '2026-11-03 09:00:00']);
    listTodo($this->user, ['done_at' => '2026-11-03 09:00:00']);
    listTodo($this->user)->delete();
    listTodo($this->colleagueUser);

    $this->actingAs($this->user)->get('/workspace/guide')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('todoCount', 2));
    $this->actingAs($this->colleagueUser)->get('/workspace/guide')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('todoCount', 1));
    $this->actingAs($this->boss)->get('/workspace/guide')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('todoCount', 0));
});

it('counts in one query', function () {
    listTodo($this->user);
    listTodo($this->user, ['started_at' => '2026-11-03 09:00:00']);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        if (str_contains($query->sql, 'personal_todos')) {
            $queries[] = $query->sql;
        }
    });

    $this->actingAs($this->user)->get('/workspace/guide')->assertOk();

    expect($queries)->toHaveCount(1);
});

// ---- Delete and Undo ----

it('deletes a to-do into a 7-day trash its owner can restore it from', function () {
    $todo = listTodo($this->user, ['due_on' => '2026-11-05', 'started_at' => '2026-11-03 09:00:00']);

    $this->actingAs($this->user)->delete("/workspace/todos/{$todo->id}")
        ->assertSessionHas('workspace_flash.message', 'To-do deleted.');

    expect(PersonalTodo::query()->whereKey($todo->id)->exists())->toBeFalse()
        ->and(PersonalTodo::withTrashed()->whereKey($todo->id)->exists())->toBeTrue()
        ->and(PersonalTodosQuery::for($this->user)['doing'])->toBe([]);

    $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/restore")->assertSessionHasNoErrors();

    $todo->refresh();
    expect($todo->trashed())->toBeFalse()
        ->and($todo->due_on?->toDateString())->toBe('2026-11-05')
        ->and($todo->started_at?->toIso8601String())->toBe(iso('2026-11-03 09:00:00'))
        ->and(array_column(PersonalTodosQuery::for($this->user)['doing'], 'id'))->toBe([$todo->id]);
});

it('lets nobody but the owner restore a deleted to-do', function () {
    $todo = listTodo($this->user);
    $todo->delete();

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        $this->actingAs($other)->post("/workspace/todos/{$todo->id}/restore")->assertNotFound();
    }

    expect($todo->refresh()->trashed())->toBeTrue();
});

it('never edits a deleted to-do', function () {
    $todo = listTodo($this->user);
    $todo->delete();

    $this->actingAs($this->user)->patch("/workspace/todos/{$todo->id}", ['title' => 'Back?'])->assertNotFound();
    $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/move", ['project_id' => $this->project->id])->assertNotFound();

    expect(Task::query()->where('title', 'Call the office')->exists())->toBeFalse();
});

it('moves a to-do for good: no Undo brings it back beside its task', function () {
    $todo = listTodo($this->user, ['title' => 'Survey the bridge']);

    $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/move", ['project_id' => $this->project->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post("/workspace/todos/{$todo->id}/restore")->assertNotFound();

    expect(PersonalTodo::withTrashed()->whereKey($todo->id)->exists())->toBeFalse()
        ->and(Task::query()->where('title', 'Survey the bridge')->exists())->toBeTrue();
});

it('prunes deleted to-dos after 7 days', function () {
    $old = listTodo($this->user, ['title' => 'Old']);
    $old->delete();
    $old->forceFill(['deleted_at' => now()->subDays(8)])->saveQuietly();
    $recent = listTodo($this->user, ['title' => 'Recent']);
    $recent->delete();
    $recent->forceFill(['deleted_at' => now()->subDays(6)])->saveQuietly();
    $kept = listTodo($this->user, ['title' => 'Kept', 'created_at' => now()->subDays(30)]);

    $this->artisan('workspace:prune-trashed --pretend')->assertSuccessful();
    expect(PersonalTodo::withTrashed()->count())->toBe(3);

    $this->artisan('workspace:prune-trashed')->assertSuccessful();

    expect(PersonalTodo::withTrashed()->orderBy('id')->pluck('title')->all())->toBe(['Recent', 'Kept'])
        ->and($kept->refresh()->trashed())->toBeFalse();
});

// ---- Quick add says what it made ----

it('flashes the key of what quick add made, so My work can show it', function () {
    $this->actingAs($this->user)->from('/workspace/my')
        ->post('/workspace/quick-add', ['title' => 'Call the office', 'personal' => true])
        ->assertRedirect('/workspace/my')
        ->assertSessionHas('workspace_flash.created_key', 'todo-'.PersonalTodo::query()->sole()->id);

    $this->actingAs($this->user)->from('/workspace/my')
        ->post('/workspace/quick-add', ['title' => 'Survey the bridge', 'project_id' => $this->project->id])
        ->assertRedirect('/workspace/my')
        ->assertSessionHas('workspace_flash.created_key', 'task-'.Task::query()->where('title', 'Survey the bridge')->sole()->id);
});

it('flashes no key when quick add makes nothing', function () {
    $this->actingAs($this->user)->from('/workspace/my')
        ->post('/workspace/quick-add', ['title' => 'Collect the forms @sita', 'personal' => true])
        ->assertSessionHasErrors('project_id')
        ->assertSessionMissing('workspace_flash.created_key');
});
