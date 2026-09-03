<?php

namespace App\Services\Workspace;

use App\Models\Subtask;
use App\Models\Task;
use App\Support\ServiceResult;
use Throwable;

class CreateSubtaskService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Task $task, int $userId, array $attributes): ServiceResult
    {
        $body = trim((string) ($attributes['body'] ?? ''));
        if ($body === '') {
            return ServiceResult::failure('Todo text is required.', 422);
        }

        try {
            $position = ((int) Subtask::query()
                ->where('task_id', $task->id)
                ->where('user_id', $userId)
                ->max('position')) + 1;

            $subtask = Subtask::create([
                'task_id' => $task->id,
                'user_id' => $userId,
                'body' => $body,
                'is_done' => false,
                'due_at' => $attributes['due_at'] ?? null,
                'position' => $position,
            ]);

            return ServiceResult::success($subtask, 'Todo added.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
