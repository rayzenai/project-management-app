<?php

namespace App\Services\Workspace;

use App\Models\Project;
use App\Models\Task;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bulk re-sorts a group of tasks within a project. The kanban board sends
 * the ordered list of task ids for a column after a drag-and-drop, and this
 * service renumbers their sort_order in 100-step increments so future
 * insertions can be bisected cheaply.
 *
 * If $status is provided, every listed task that doesn't already have that
 * status is bumped to it (same effect as a cross-column drag).
 */
class ReorderTasksService
{
    /**
     * @param  array<int, int>  $orderedTaskIds
     */
    public function execute(Project $project, array $orderedTaskIds, ?string $status = null): ServiceResult
    {
        $orderedTaskIds = array_values(array_filter(array_map('intval', $orderedTaskIds)));
        if ($orderedTaskIds === []) {
            return ServiceResult::failure('No tasks supplied.', 422);
        }

        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $orderedTaskIds)
            ->get()
            ->keyBy('id');

        if ($tasks->count() !== count($orderedTaskIds)) {
            return ServiceResult::failure('Some tasks do not belong to this project.', 422);
        }

        try {
            $statusChanges = [];

            DB::transaction(function () use ($orderedTaskIds, $tasks, $status, &$statusChanges) {
                $sort = 100;
                foreach ($orderedTaskIds as $taskId) {
                    /** @var Task $task */
                    $task = $tasks[$taskId];
                    $task->sort_order = $sort;
                    if ($status !== null && $task->status !== $status) {
                        $statusChanges[] = [
                            'task' => $task,
                            'from' => $task->status,
                            'to' => $status,
                        ];
                        $task->status = $status;
                        $task->status_updated_at = now();
                    }
                    $task->save();
                    $sort += 100;
                }
            });

            return ServiceResult::success(message: $this->describe($statusChanges));
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }

    /**
     * @param  array<int, array{task: Task, from: ?string, to: string}>  $changes
     */
    private function describe(array $changes): string
    {
        if ($changes === []) {
            return 'Order updated.';
        }

        if (count($changes) === 1) {
            $c = $changes[0];
            $title = $c['task']->short_title ?: $c['task']->title;

            return sprintf(
                '%s · Status: %s → %s',
                $title,
                $this->statusLabel($c['from']),
                $this->statusLabel($c['to']),
            );
        }

        return count($changes).' tasks moved to '.$this->statusLabel($changes[0]['to']).'.';
    }

    private function statusLabel(?string $status): string
    {
        if (! $status) {
            return 'none';
        }

        return config("project-management.statuses.{$status}.label", $status);
    }
}
