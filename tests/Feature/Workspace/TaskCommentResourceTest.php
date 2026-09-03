<?php

use App\Http\Resources\TaskCommentResource;
use App\Models\Member;
use App\Models\ProjectActivity;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Http\Request;

it('shapes the comment resource with mentions, author, and can_edit', function () {
    $author = User::factory()->create(['name' => 'Kiran']);
    $asha = Member::factory()->create(['name' => 'Asha']);
    $task = Task::factory()->create();
    $comment = TaskComment::factory()->for($task)->create([
        'user_id' => $author->id,
        'body' => "hi @[Asha](member:{$asha->id})",
        'mentioned_member_ids' => [$asha->id],
    ]);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $author);
    $array = TaskCommentResource::make($comment)->toArray($request);

    expect($array['mentions'])->toBe([['member_id' => $asha->id, 'name' => 'Asha']])
        ->and($array['author']['name'])->toBe('Kiran')
        ->and($array['can_edit'])->toBeTrue()
        ->and($array['body'])->toBe("hi @[Asha](member:{$asha->id})");
});

it('can_edit is false for a non-author', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $task = Task::factory()->create();
    $comment = TaskComment::factory()->for($task)->create(['user_id' => $author->id]);

    $request = Request::create('/');
    $request->setUserResolver(fn () => $other);

    expect(TaskCommentResource::make($comment)->toArray($request)['can_edit'])->toBeFalse();
});

it('writes a commented activity on create', function () {
    $task = Task::factory()->create();
    TaskComment::factory()->for($task)->create();

    expect(ProjectActivity::where('action', ProjectActivity::ACTION_COMMENTED)->exists())->toBeTrue();
});
