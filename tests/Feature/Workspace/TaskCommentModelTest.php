<?php

use App\Models\Task;
use App\Models\TaskComment;

it('relates a comment to its task and soft-deletes', function () {
    $task = Task::factory()->create();
    $comment = TaskComment::factory()->for($task)->create(['body' => 'hello']);

    expect($task->comments()->count())->toBe(1)
        ->and($comment->task->id)->toBe($task->id);

    $comment->delete();
    expect(TaskComment::withTrashed()->find($comment->id)->trashed())->toBeTrue();
});
