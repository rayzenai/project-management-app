<?php

use App\Models\Member;
use App\Models\Organization;
use App\Models\PersonalTodo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;

/*
 * The app's to-do list: GET /api/v1/workspace/todos (Doing, To do, the
 * last week's Done), `doing` on PATCH, restore after a delete, and
 * `todo_count` on /user. Each its owner's alone. Today is Wednesday
 * 2026-11-04.
 */

beforeEach(function () {
    config(['project-management.super_admins' => ['boss@example.com']]);
    CarbonImmutable::setTestNow('2026-11-04 10:00:00');

    $this->org = Organization::factory()->create();

    $this->user = User::factory()->create(['name' => 'Bikash Karki']);
    Member::factory()->linkedTo($this->user)->create(['organization_id' => $this->org->id, 'name' => 'Bikash Karki']);
    $this->colleagueUser = User::factory()->create(['name' => 'Sita Sharma']);
    Member::factory()->linkedTo($this->colleagueUser)->create(['organization_id' => $this->org->id, 'name' => 'Sita Sharma']);

    $this->adminUser = User::factory()->create();
    Member::factory()->linkedTo($this->adminUser)->create(['organization_id' => $this->org->id, 'is_office_admin' => true]);
    $this->boss = User::factory()->create(['email' => 'boss@example.com']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** @param  array<string, mixed>  $attributes */
function apiListTodo(User $user, array $attributes = []): PersonalTodo
{
    return PersonalTodo::forceCreate(['user_id' => $user->id, 'title' => 'Call the office', ...$attributes]);
}

it('lists the caller\'s to-dos in three lists', function () {
    $doing = apiListTodo($this->user, ['title' => 'Doing', 'started_at' => '2026-11-03 09:00:00']);
    $open = apiListTodo($this->user, ['title' => 'Open', 'due_on' => '2026-11-06']);
    $done = apiListTodo($this->user, ['title' => 'Done', 'done_at' => '2026-11-04 08:00:00']);
    apiListTodo($this->colleagueUser, ['title' => 'Sita\'s']);
    Sanctum::actingAs($this->user);

    $this->getJson('/api/v1/workspace/todos')
        ->assertOk()
        ->assertExactJson([
            'today' => '2026-11-04',
            'doing' => [['id' => $doing->id, 'title' => 'Doing', 'due_on' => null, 'started_at' => CarbonImmutable::parse('2026-11-03 09:00:00')->toIso8601String(), 'done_at' => null]],
            'open' => [['id' => $open->id, 'title' => 'Open', 'due_on' => '2026-11-06', 'started_at' => null, 'done_at' => null]],
            'done' => [['id' => $done->id, 'title' => 'Done', 'due_on' => null, 'started_at' => null, 'done_at' => CarbonImmutable::parse('2026-11-04 08:00:00')->toIso8601String()]],
        ]);
});

it('shows nobody else the caller\'s to-dos, and asks a guest to sign in', function () {
    $todo = apiListTodo($this->user, ['started_at' => '2026-11-03 09:00:00']);

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        Sanctum::actingAs($other);

        $json = $this->getJson('/api/v1/workspace/todos')->assertOk()->json();

        expect(collect([...$json['doing'], ...$json['open'], ...$json['done']])->pluck('id'))->not->toContain($todo->id);
    }

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/workspace/todos')->assertUnauthorized();
});

it('starts and stops a to-do for its owner only', function () {
    $todo = apiListTodo($this->user);

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/workspace/todos/{$todo->id}", ['doing' => true])->assertNotFound();
    }

    expect($todo->refresh()->started_at)->toBeNull();

    Sanctum::actingAs($this->user);
    $this->patchJson("/api/v1/workspace/todos/{$todo->id}", ['doing' => true])
        ->assertOk()
        ->assertJsonPath('data.started_at', CarbonImmutable::now()->toIso8601String())
        ->assertJsonPath('data.done_at', null);

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        Sanctum::actingAs($other);
        $this->patchJson("/api/v1/workspace/todos/{$todo->id}", ['doing' => false])->assertNotFound();
    }

    expect($todo->refresh()->started_at)->not->toBeNull();

    Sanctum::actingAs($this->user);
    $this->patchJson("/api/v1/workspace/todos/{$todo->id}", ['doing' => false])
        ->assertOk()
        ->assertJsonPath('data.started_at', null);
});

it('restores a deleted to-do for its owner only', function () {
    $todo = apiListTodo($this->user, ['title' => 'Pay the bill']);
    Sanctum::actingAs($this->user);

    $this->deleteJson("/api/v1/workspace/todos/{$todo->id}")->assertOk()->assertJsonPath('message', 'To-do deleted.');

    foreach ([$this->colleagueUser, $this->adminUser, $this->boss] as $other) {
        Sanctum::actingAs($other);
        $this->postJson("/api/v1/workspace/todos/{$todo->id}/restore")->assertNotFound();
    }

    expect($todo->refresh()->trashed())->toBeTrue();

    Sanctum::actingAs($this->user);
    $this->postJson("/api/v1/workspace/todos/{$todo->id}/restore")
        ->assertOk()
        ->assertJsonPath('data.id', $todo->id)
        ->assertJsonPath('data.title', 'Pay the bill');

    expect($todo->refresh()->trashed())->toBeFalse();
});

it('says how many to-dos are open on /user', function () {
    apiListTodo($this->user);
    apiListTodo($this->user, ['started_at' => '2026-11-03 09:00:00']);
    apiListTodo($this->user, ['done_at' => '2026-11-03 09:00:00']);
    apiListTodo($this->user)->delete();
    apiListTodo($this->colleagueUser);

    Sanctum::actingAs($this->user);
    $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('todo_count', 2);

    Sanctum::actingAs($this->boss);
    $this->getJson('/api/v1/user')->assertOk()->assertJsonPath('todo_count', 0);
});
