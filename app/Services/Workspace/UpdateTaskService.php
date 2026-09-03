<?php

namespace App\Services\Workspace;

use App\Models\Task;
use App\Support\ServiceResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class UpdateTaskService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Task $task, array $attributes): ServiceResult
    {
        try {
            return DB::transaction(function () use ($task, $attributes): ServiceResult {
                $before = $task->only(['title', 'status', 'priority', 'progress', 'deadline_at', 'sort_order']);
                $beforeMeta = [
                    'category' => $task->category,
                    'deadline_type' => $task->deadline_type,
                    'responsible_ministry' => $task->responsible_ministry,
                ];

                foreach (['title', 'description', 'status', 'priority', 'progress', 'deadline_at', 'sort_order', 'status_note', 'source_url'] as $key) {
                    if (! array_key_exists($key, $attributes)) {
                        continue;
                    }

                    if ($key === 'progress' && $attributes[$key] === null) {
                        continue;
                    }

                    $task->{$key} = $attributes[$key];
                }

                foreach (['item_number', 'category', 'deadline_type', 'responsible_ministry', 'title_np', 'description_np'] as $metaKey) {
                    if (array_key_exists($metaKey, $attributes)) {
                        $task->{$metaKey} = $attributes[$metaKey] ?: null;
                    }
                }

                if ($task->isDirty('status')) {
                    $task->status_updated_at = now();
                }

                $task->save();

                $message = $this->describeChanges($task, $before, $beforeMeta);

                return ServiceResult::success($task->fresh(), $message);
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::fromException($e);
        }
    }

    /**
     * Build a human-readable diff message like
     * "Status: in_progress → done · Progress: 50% → 100%".
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $beforeMeta
     */
    private function describeChanges(Task $task, array $before, array $beforeMeta): string
    {
        $parts = [];

        if ($before['status'] !== $task->status) {
            $parts[] = 'Status: '.$this->statusLabel($before['status']).' → '.$this->statusLabel($task->status);
        }

        if (($before['priority'] ?? null) !== $task->priority) {
            $parts[] = 'Priority: '.($before['priority'] ?? 'medium').' → '.$task->priority;
        }

        if ((int) $before['progress'] !== (int) $task->progress) {
            $parts[] = 'Progress: '.(int) $before['progress'].'% → '.(int) $task->progress.'%';
        }

        $beforeDue = $before['deadline_at'] instanceof Carbon ? $before['deadline_at']->toDateString() : (string) ($before['deadline_at'] ?? '');
        $afterDue = $task->deadline_at?->toDateString() ?? '';
        if ($beforeDue !== $afterDue) {
            $parts[] = 'Due: '.($beforeDue ?: 'none').' → '.($afterDue ?: 'none');
        }

        if ($before['title'] !== $task->title) {
            $parts[] = 'Renamed';
        }

        foreach ($beforeMeta as $key => $val) {
            if ($val !== $task->{$key}) {
                $parts[] = ucwords(str_replace('_', ' ', $key)).': '.($val ?: 'none').' → '.($task->{$key} ?: 'none');
            }
        }

        if ($parts === []) {
            return 'Task updated.';
        }

        return implode(' · ', $parts);
    }

    private function statusLabel(?string $status): string
    {
        if (! $status) {
            return 'none';
        }

        return config("project-management.statuses.{$status}.label", $status);
    }
}
