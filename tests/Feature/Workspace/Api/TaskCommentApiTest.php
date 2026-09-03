<?php

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('creates, lists, edits own, forbids editing others, and deletes', function () {
    $author = User::factory()->create();
    $task = Task::factory()->create();

    $created = $this->actingAs($author, 'sanctum')
        ->postJson("/api/v1/workspace/tasks/{$task->id}/comments", ['body' => 'first'])
        ->assertStatus(201)->json('data');

    $this->actingAs($author, 'sanctum')
        ->getJson("/api/v1/workspace/tasks/{$task->id}/comments")
        ->assertSuccessful()->assertJsonCount(1, 'data');

    $this->actingAs($author, 'sanctum')
        ->patchJson("/api/v1/workspace/comments/{$created['id']}", ['body' => 'edited'])
        ->assertSuccessful();

    $other = User::factory()->create();
    $this->actingAs($other, 'sanctum')
        ->patchJson("/api/v1/workspace/comments/{$created['id']}", ['body' => 'nope'])
        ->assertForbidden();

    $this->actingAs($other, 'sanctum')
        ->deleteJson("/api/v1/workspace/comments/{$created['id']}")
        ->assertForbidden();

    $this->actingAs($author, 'sanctum')
        ->deleteJson("/api/v1/workspace/comments/{$created['id']}")
        ->assertSuccessful();
    expect(TaskComment::withTrashed()->find($created['id'])->trashed())->toBeTrue();

    $this->actingAs($author, 'sanctum')
        ->postJson("/api/v1/workspace/comments/{$created['id']}/restore")
        ->assertSuccessful();
    expect(TaskComment::find($created['id']))->not->toBeNull();
});

it('lists a thread of comments with a bounded query count (batched, no 2N)', function () {
    $author = User::factory()->create();
    $task = Task::factory()->create();

    foreach (range(1, 3) as $i) {
        TaskComment::factory()->for($task)->create(['user_id' => $author->id]);
    }

    $this->actingAs($author, 'sanctum')->getJson("/api/v1/workspace/tasks/{$task->id}/comments");

    DB::enableQueryLog();
    $this->actingAs($author, 'sanctum')
        ->getJson("/api/v1/workspace/tasks/{$task->id}/comments")
        ->assertSuccessful()
        ->assertJsonCount(3, 'data');

    expect(count(DB::getQueryLog()))->toBeLessThan(10);
    DB::disableQueryLog();
});
