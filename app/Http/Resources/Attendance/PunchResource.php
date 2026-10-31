<?php

namespace App\Http\Resources\Attendance;

use App\Models\AttendancePunch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One punch as its owner sees it: what, when (the server's time) and where
 * from. `place_name` is the nearest office place, when the query loaded it.
 *
 * @mixin AttendancePunch
 */
class PunchResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{id: int, kind: string, at: string, work_date: string, source: string, place: string, place_name: string|null, distance_m: int|null, note: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'kind' => $this->kind,
            'at' => $this->punched_at->toIso8601String(),
            'work_date' => $this->work_date->toDateString(),
            'source' => $this->source,
            'place' => $this->place,
            'place_name' => $this->relationLoaded('attendancePlace') ? $this->attendancePlace?->name : null,
            'distance_m' => $this->distance_m,
            'note' => $this->note,
        ];
    }
}
