<?php

namespace App\Http\Resources\Attendance;

use App\Models\LeaveRequest;
use App\Services\Attendance\CancelLeaveService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One request for leave or kaaj. `member` and `decided_by` only when the
 * query loaded them; `can_cancel` says whether its person may still cancel
 * it (CancelLeaveService::cancellable).
 *
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'days' => (int) round($this->starts_on->diffInDays($this->ends_on)) + 1,
            'reason' => $this->reason,
            'place' => $this->place,
            'status' => $this->status,
            'can_cancel' => CancelLeaveService::cancellable($this->resource),
            'member' => $this->whenLoaded('member', fn (): ?array => $this->member === null ? null : [
                'id' => $this->member->id,
                'name' => $this->member->name,
                'designation' => $this->member->relationLoaded('designation') ? $this->member->designation?->name : null,
            ]),
            'decided_by' => $this->whenLoaded('decider', fn (): ?array => $this->decider === null ? null : [
                'id' => $this->decider->id,
                'name' => $this->decider->name,
            ]),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
