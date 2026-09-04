<?php

namespace App\Services\Workspace;

use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The killer feature: create a task and assign it to one or more members in a
 * single atomic call. Designed for inline quick-add boxes on Today / My
 * Workspace where typing a title + selecting an assignee should result in a
 * fully-formed assigned task with no follow-up forms.
 */
class QuickAddTaskService
{
    /**
     * @param  array<int, int>  $assigneeMemberIds  list of member IDs to assign; the controller defaults to the acting user's member
     */
    public function execute(
        Project $project,
        string $title,
        array $assigneeMemberIds,
        ?string $deadline = null,
        ?string $priority = 'medium',
        ?int $authorUserId = null,
        ?string $status = null,
        ?string $description = null,
    ): ServiceResult {
        $title = trim($title);
        if ($title === '') {
            return ServiceResult::failure('Title is required.', 422);
        }

        // An explicit status wins; anything else keeps the long-standing default
        // so API callers that never sent one are unaffected. The UI always sends
        // one, so the modal decides its own default.
        $statuses = array_keys((array) config('project-management.statuses'));
        $status = in_array($status, $statuses, true) ? $status : 'unclear';

        // An empty assignee list is allowed: the task is created unassigned and can
        // be assigned later. Any members that ARE provided must be on the project.
        $assigneeMemberIds = array_values(array_unique(array_map('intval', $assigneeMemberIds)));

        if ($assigneeMemberIds !== []) {
            $inScope = Member::assignableFor($project)->pluck('id')->all();
            if (array_diff($assigneeMemberIds, $inScope) !== []) {
                return ServiceResult::failure("That person is not on this project's teams.", 422);
            }
        }

        try {
            return DB::transaction(function () use ($project, $title, $assigneeMemberIds, $deadline, $priority, $status, $description): ServiceResult {
                $itemNumber = $this->nextItemNumber($project->id);
                $sortOrder = ((int) Task::query()->where('project_id', $project->id)->max('sort_order')) + 100;

                $task = Task::create([
                    'project_id' => $project->id,
                    'title' => $title,
                    'slug' => $this->uniqueSlug($title, $itemNumber),
                    'description' => $description ?? '',
                    'status' => $status,
                    'priority' => $priority ?: 'medium',
                    'progress' => 0,
                    'deadline_at' => $deadline,
                    'item_number' => $itemNumber,
                    'sort_order' => $sortOrder,
                ]);

                foreach ($assigneeMemberIds as $memberId) {
                    ProjectAssignment::create([
                        'member_id' => $memberId,
                        'task_id' => $task->id,
                        'priority' => $priority ?: 'medium',
                        'personal_progress' => 0,
                    ]);
                }

                return ServiceResult::success(
                    data: $task->fresh(['assignments.member']),
                    message: $assigneeMemberIds === [] ? 'Task created.' : 'Task created and assigned.',
                );
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }

    private function nextItemNumber(int $projectId): int
    {
        $max = (int) (Task::withTrashed()
            ->where('project_id', $projectId)
            ->max(DB::raw("CAST(metadata->>'item_number' AS INTEGER)")) ?? 0);

        return $max + 1;
    }

    private function uniqueSlug(string $title, int $itemNumber): string
    {
        $base = $itemNumber.'-'.Str::slug(Str::limit($title, 60, ''));
        if (Str::endsWith($base, '-')) {
            $base .= 'task';
        }

        $slug = $base;
        $i = 2;
        while (Task::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }
}
