<?php

namespace App\Services\Workspace;

use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Support\ServiceResult;
use Throwable;

class AssignMemberService
{
    /**
     * @param  array<string, mixed>  $attributes  optional: role, priority, personal_due_at, personal_status_note
     */
    public function execute(Task $task, int $memberId, array $attributes = []): ServiceResult
    {
        if (ProjectAssignment::query()->where('task_id', $task->id)->where('member_id', $memberId)->exists()) {
            return ServiceResult::failure('That person is already assigned to this task.', 409);
        }

        try {
            $assignment = ProjectAssignment::create([
                'task_id' => $task->id,
                'member_id' => $memberId,
                'role' => $attributes['role'] ?? null,
                'priority' => $attributes['priority'] ?? 'medium',
                'personal_progress' => (int) ($attributes['personal_progress'] ?? 0),
                'personal_due_at' => $attributes['personal_due_at'] ?? null,
                'personal_status_note' => $attributes['personal_status_note'] ?? null,
            ]);

            return ServiceResult::success($assignment->fresh('member'), 'Member assigned.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
