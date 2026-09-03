<?php

namespace App\Services\Workspace;

use App\Models\Project;
use App\Models\Task;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class CreateTaskService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Project $project, array $attributes): ServiceResult
    {
        $title = trim((string) ($attributes['title'] ?? ''));
        if ($title === '') {
            return ServiceResult::failure('Title is required.', 422);
        }

        try {
            return DB::transaction(function () use ($project, $attributes, $title): ServiceResult {
                $itemNumber = isset($attributes['item_number']) && $attributes['item_number'] !== ''
                    ? (int) $attributes['item_number']
                    : $this->nextItemNumber($project->id);

                $task = new Task([
                    'project_id' => $project->id,
                    'title' => $title,
                    'description' => $attributes['description'] ?? '',
                    'status' => $attributes['status'] ?? 'unclear',
                    'progress' => (int) ($attributes['progress'] ?? 0),
                    'deadline_at' => $attributes['deadline_at'] ?? null,
                    'status_note' => $attributes['status_note'] ?? null,
                    'source_url' => $attributes['source_url'] ?? null,
                    'sort_order' => $this->nextSortOrder($project->id),
                    'item_number' => $itemNumber,
                ]);

                $task->slug = $this->uniqueSlug($title, $itemNumber);

                foreach (['category', 'deadline_type', 'responsible_ministry', 'title_np', 'description_np'] as $metaKey) {
                    if (array_key_exists($metaKey, $attributes) && $attributes[$metaKey] !== null && $attributes[$metaKey] !== '') {
                        $task->{$metaKey} = $attributes[$metaKey];
                    }
                }

                $task->save();

                return ServiceResult::success($task->fresh(), 'Task created.');
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }

    private function nextSortOrder(int $projectId): int
    {
        $max = Task::query()->where('project_id', $projectId)->max('sort_order');

        return ((int) $max) + 100;
    }

    /**
     * Next available item_number for this project. Auto-numbers every task
     * sequentially per project so users see #1, #2, #3 ... in chronological
     * creation order regardless of project.
     */
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
