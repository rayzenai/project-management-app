<?php

namespace App\Http\Resources;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * Workspace resources are consumed by Inertia and serialized inline; no
     * outer "data" wrapper.
     */
    public static $wrap = null;

    /**
     * The short form of a task used in dependency lists and pickers.
     *
     * @return array{id: int, slug: string, title: string, number: int, item_number: int|null, status: string, status_label: string, is_complete: bool, is_late: bool, is_overdue: bool, deadline_at: string|null, completed_at: string|null}
     */
    public static function reference(Task $task): array
    {
        return [
            'id' => $task->id,
            'slug' => $task->slug,
            'title' => $task->title,
            'number' => $task->number,
            'item_number' => $task->item_number,
            'status' => $task->status,
            'status_label' => $task->status_label,
            'is_complete' => $task->isComplete(),
            'is_late' => (bool) $task->is_late,
            'is_overdue' => (bool) $task->is_overdue,
            'deadline_at' => $task->deadline_at?->toDateString(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'number' => $this->number,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'slug' => $this->project->slug,
                'code' => $this->project->code,
                'title' => $this->project->title,
            ]),
            'slug' => $this->slug,
            'title' => $this->title,
            'short_title' => $this->short_title,
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->status_label,
            'status_color' => $this->status_color,
            'status_note' => $this->status_note,
            'status_updated_at' => $this->status_updated_at?->toIso8601String(),
            'priority' => $this->priority ?? 'medium',
            'progress' => (int) $this->progress,
            'sort_order' => (int) $this->sort_order,
            'deadline_at' => $this->deadline_at?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_late' => (bool) $this->is_late,
            'is_overdue' => (bool) $this->is_overdue,
            'days_relative_label' => $this->days_relative_label,
            'source_url' => $this->source_url,
            'source_links' => $this->source_links ?? [],
            'freshness' => $this->freshness,
            'metadata' => $this->metadata ?? [],
            // Convenience fields surfaced from metadata for UI clarity.
            'item_number' => $this->item_number,
            'category' => $this->category,
            'category_label' => $this->category_label,
            'category_color' => $this->category_color,
            'deadline_type' => $this->deadline_type,
            'deadline_label' => $this->deadline_label,
            'responsible_ministry' => $this->responsible_ministry,
            'title_np' => $this->title_np,
            'description_np' => $this->description_np,
            'assignments' => $this->whenLoaded(
                'assignments',
                fn () => AssignmentResource::collection($this->assignments)->resolve(),
            ),
            'assignments_count' => $this->whenCounted('assignments'),
            // Finish-to-start links within the project: what this task waits
            // for, and what waits for it.
            'dependencies' => $this->whenLoaded('dependencies', fn () => $this->dependencies->map(fn (Task $t): array => self::reference($t))->values()->all()),
            'dependents' => $this->whenLoaded('dependents', fn () => $this->dependents->map(fn (Task $t): array => self::reference($t))->values()->all()),
            'notes_count' => $this->whenCounted('notes'),
            'contacts_count' => $this->whenCounted('contacts'),
            // The board's pull-request mark; only checked where code shows.
            'has_pull_request' => $this->whenExistsLoaded('pullRequests'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
