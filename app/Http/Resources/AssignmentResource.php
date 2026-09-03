<?php

namespace App\Http\Resources;

use App\Models\ProjectAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectAssignment
 */
class AssignmentResource extends JsonResource
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
            'member_id' => $this->member_id,
            'member' => $this->whenLoaded('member', fn () => [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'email' => $this->member->email,
                'user_id' => $this->member->user_id,
            ]),
            'role' => $this->role,
            'priority' => $this->priority,
            'is_focused' => (bool) $this->is_focused,
            'snoozed_until' => $this->snoozed_until?->toDateString(),
            'is_snoozed' => $this->isSnoozed(),
            'personal_progress' => (int) $this->personal_progress,
            'personal_due_at' => $this->personal_due_at?->toDateString(),
            'personal_status_note' => $this->personal_status_note,
            'task' => $this->whenLoaded('task', fn () => new TaskResource($this->task)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
