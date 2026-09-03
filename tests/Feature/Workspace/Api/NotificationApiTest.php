<?php

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;

it('lists, counts, and marks notifications read for the caller only', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $me->notify(new TaskAssigned(Task::factory()->create(), 'Kiran'));
    $other->notify(new TaskAssigned(Task::factory()->create(), 'Kiran'));

    $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications')
        ->assertSuccessful()->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('meta.current_page', 1);

    $this->actingAs($me, 'sanctum')->getJson('/api/v1/notifications/unread-count')
        ->assertJsonPath('data.count', 1);

    $id = $me->notifications()->first()->id;
    $this->actingAs($me, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertSuccessful();
    expect($me->fresh()->unreadNotifications()->count())->toBe(0);

    $other->notify(new TaskAssigned(Task::factory()->create(), 'Kiran'));
    $me->notify(new TaskAssigned(Task::factory()->create(), 'Kiran'));
    $this->actingAs($me, 'sanctum')->postJson('/api/v1/notifications/read-all')->assertSuccessful();
    expect($me->fresh()->unreadNotifications()->count())->toBe(0);
});
