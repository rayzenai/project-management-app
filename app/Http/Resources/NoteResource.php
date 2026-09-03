<?php

namespace App\Http\Resources;

use App\Models\ProjectNote;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectNote
 */
class NoteResource extends JsonResource
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
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
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
            'type' => $this->type,
            'type_label' => ProjectNote::TYPES[$this->type] ?? ucfirst((string) $this->type),
            'body' => $this->body,
            'happened_at' => $this->happened_at?->toDateString(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
