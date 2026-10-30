<?php

namespace App\Services\Workspace;

use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Support\Assignees;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

class AssignMemberService
{
    public function __construct(private AddTeamMemberService $addToTeam) {}

    /**
     * Someone not yet on the project's team, whom the actor may bring onto
     * it (Assignees), joins it as a member first.
     *
     * @param  array<string, mixed>  $attributes  optional: role, priority, personal_due_at, personal_status_note
     */
    public function execute(Task $task, int $memberId, array $attributes = [], ?Authenticatable $actor = null): ServiceResult
    {
        if (ProjectAssignment::query()->where('task_id', $task->id)->where('member_id', $memberId)->exists()) {
            return ServiceResult::failure('That person is already assigned to this task.', 409);
        }

        $project = $task->loadMissing('project')->project;
        $joiners = Assignees::joiners($actor, $project, [$memberId]);

        if ($joiners === null) {
            return ServiceResult::failure("That person is not on this project's teams.", 422, ['member_id' => "That person is not on this project's teams."]);
        }

        try {
            $assignment = DB::transaction(function () use ($task, $memberId, $attributes, $project, $joiners): ProjectAssignment {
                foreach ($joiners as $member) {
                    $this->addToTeam->execute($project->ensureTeam(), $member);
                }

                return ProjectAssignment::create([
                    'task_id' => $task->id,
                    'member_id' => $memberId,
                    'role' => $attributes['role'] ?? null,
                    'priority' => $attributes['priority'] ?? 'medium',
                    'personal_progress' => (int) ($attributes['personal_progress'] ?? 0),
                    'personal_due_at' => $attributes['personal_due_at'] ?? null,
                    'personal_status_note' => $attributes['personal_status_note'] ?? null,
                ]);
            });

            return ServiceResult::success($assignment->fresh('member'), 'Member assigned.');
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }
}
