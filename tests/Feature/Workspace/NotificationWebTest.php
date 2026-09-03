<?php

use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssigned;

it('shares the unread notification count on workspace pages', function () {
    $user = User::factory()->create();
    $user->notify(new TaskAssigned(Task::factory()->create(), 'Kiran'));

    $this->actingAs($user)->get('/workspace')
        ->assertInertia(fn ($page) => $page->where('unreadNotifications', 1));
});

it('renders the notifications inbox page', function () {
    $user = User::factory()->create();
    $user->notify(new TaskAssigned(Task::factory()->create(), 'Kiran'));

    $this->actingAs($user)->get('/workspace/notifications')
        ->assertInertia(fn ($page) => $page->component('Notifications/Index'));
});

it('marks a notification read from the web', function () {
    $user = User::factory()->create();
    $user->notify(new TaskAssigned(Task::factory()->create(), 'Kiran'));
    $id = $user->notifications()->first()->id;

    $this->actingAs($user)->post("/workspace/notifications/{$id}/read")->assertRedirect();
    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});
