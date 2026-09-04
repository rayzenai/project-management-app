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
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
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
            'notes_count' => $this->whenCounted('notes'),
            'contacts_count' => $this->whenCounted('contacts'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
