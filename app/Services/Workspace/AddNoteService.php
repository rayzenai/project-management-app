<?php

namespace App\Services\Workspace;

use App\Models\ProjectNote;
use App\Models\Task;
use App\Support\ServiceResult;
use Throwable;

class AddNoteService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Task $task, int $userId, array $attributes): ServiceResult
    {
        $body = trim((string) ($attributes['body'] ?? ''));
        if ($body === '') {
            return ServiceResult::failure('Note body is required.', 422);
        }

        try {
            $note = ProjectNote::create([
                'task_id' => $task->id,
                'user_id' => $userId,
                'type' => $attributes['type'] ?? 'general',
                'body' => $body,
                'happened_at' => $attributes['happened_at'] ?? now()->toDateString(),
            ]);

            return ServiceResult::success($note->fresh('user'), 'Note added.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
