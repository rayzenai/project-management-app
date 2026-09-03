<?php

namespace App\Http\Resources;

use App\Models\ProjectContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectContact
 */
class ContactResource extends JsonResource
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
            'name' => $this->name,
            'role' => $this->role,
            'email' => $this->email,
            'phone' => $this->phone,
            'organization' => $this->organization,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
