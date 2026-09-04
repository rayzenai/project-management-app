<?php

use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionedInComment;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskDeadlineDue;
use App\Notifications\TaskStatusChanged;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->task = Task::factory()->create(['title' => 'File the report']);

    $this->user->notify(new TaskAssigned($this->task, 'Kiran'));
    $this->user->notify(new TaskStatusChanged($this->task, 'Done', 'Kiran'));
    $this->user->notify(new MentionedInComment($this->task, 'Kiran', 'have a look'));
    $this->user->notify(new TaskDeadlineDue($this->task, 'overdue'));
});

it('counts every kind across the whole inbox', function () {
    $this->actingAs($this->user)->get('/workspace/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.all', 4)
            ->where('counts.unread', 4)
            ->where('counts.assigned', 1)
            ->where('counts.status', 1)
            ->where('counts.mention', 1)
            ->where('counts.deadline', 1)
            ->has('notifications.data', 4));
});

it('filters by kind while keeping the counts whole', function () {
    $this->actingAs($this->user)->get('/workspace/notifications?type=mention')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.type', 'mention')
            ->has('notifications.data', 1)
            // Counts describe the inbox, not the filtered page, so the chips
            // keep their numbers while a filter is on.
            ->where('counts.all', 4)
            ->where('notifications.data.0.data.kind', 'mentioned_in_comment'));
});

it('filters to unread only', function () {
    $this->user->notifications()->first()->markAsRead();

    $this->actingAs($this->user)->get('/workspace/notifications?scope=unread')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.scope', 'unread')
            ->where('counts.all', 4)
            ->where('counts.unread', 3)
            ->has('notifications.data', 3));
});

it('ignores an unknown type instead of returning nothing', function () {
    $this->actingAs($this->user)->get('/workspace/notifications?type=bogus')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.type', null)
            ->has('notifications.data', 4));
});

it('never counts or lists another user notifications', function () {
    $other = User::factory()->create();
    $other->notify(new TaskAssigned($this->task, 'Kiran'));

    $this->actingAs($this->user)->get('/workspace/notifications')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.all', 4)
            ->has('notifications.data', 4));
});

it('carries a short action line that omits the task title', function () {
    $data = (new TaskAssigned($this->task, 'Kiran'))->toArray($this->user);

    // The inbox leads with the task title, so the sentence under it must not
    // repeat it. `body` keeps the long form for the API and email.
    expect($data['action'])->toBe('Kiran assigned this to you')
        ->and($data['action'])->not->toContain('File the report')
        ->and($data['body'])->toContain('File the report');
});
