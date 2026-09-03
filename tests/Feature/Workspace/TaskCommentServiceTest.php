<?php

use App\Models\Member;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MentionedInComment;
use App\Services\Workspace\CreateTaskCommentService;
use App\Services\Workspace\UpdateTaskCommentService;
use Illuminate\Support\Facades\Notification;

it('stores resolved mention ids and notifies mentioned login-linked members', function () {
    Notification::fake();
    $task = Task::factory()->create();
    $author = User::factory()->create();
    $asha = Member::factory()->withUser()->create();

    $result = app(CreateTaskCommentService::class)->execute($task, $author, "Hi @[Asha](member:{$asha->id})");

    expect($result->success)->toBeTrue()
        ->and($result->data->mentioned_member_ids)->toBe([$asha->id]);
    Notification::assertSentTo($asha->user, MentionedInComment::class);
});

it('does not notify the author even if they mention themselves', function () {
    Notification::fake();
    $task = Task::factory()->create();
    $authorMember = Member::factory()->withUser()->create();
    $author = $authorMember->user;

    app(CreateTaskCommentService::class)->execute($task, $author, "me @[self](member:{$authorMember->id})");

    Notification::assertNotSentTo($author, MentionedInComment::class);
});

it('on edit notifies only newly added mentions', function () {
    Notification::fake();
    $task = Task::factory()->create();
    $author = User::factory()->create();
    $asha = Member::factory()->withUser()->create();
    $bob = Member::factory()->withUser()->create();

    $comment = app(CreateTaskCommentService::class)->execute($task, $author, "@[Asha](member:{$asha->id})")->data;
    Notification::fake(); // reset

    app(UpdateTaskCommentService::class)->execute($comment, "@[Asha](member:{$asha->id}) @[Bob](member:{$bob->id})");

    Notification::assertSentTo($bob->user, MentionedInComment::class);
    Notification::assertNotSentTo($asha->user, MentionedInComment::class);
});
