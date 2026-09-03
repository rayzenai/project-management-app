<?php

namespace App\Http\Resources;

use App\Models\Subtask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subtask
 */
class SubtaskResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->task_id,
            'user_id' => $this->user_id,
            'body' => $this->body,
            'is_done' => (bool) $this->is_done,
            'done_at' => $this->done_at?->toIso8601String(),
            'due_at' => $this->due_at?->toDateString(),
            'position' => (int) $this->position,
            'task' => $this->whenLoaded('task', fn () => [
                'id' => $this->task->id,
                'slug' => $this->task->slug,
                'title' => $this->task->title,
                'short_title' => $this->task->short_title,
                'project' => $this->task->relationLoaded('project') && $this->task->project ? [
                    'slug' => $this->task->project->slug,
                    'title' => $this->task->project->title,
                ] : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
