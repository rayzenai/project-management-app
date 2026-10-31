<?php

namespace App\Http\Resources\Attendance;

use App\Models\AttendanceSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization's attendance switch and hours. `enabled_by` is the name
 * of whoever last turned it on, when the query loaded it.
 *
 * @mixin AttendanceSetting
 */
class SettingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{enabled: bool, enabled_at: string|null, enabled_by: string|null, workdays: list<int>, start_time: string, end_time: string, seasons: list<array{name: string, from: string, to: string, start: string, end: string}>, grace_minutes: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'enabled' => (bool) $this->enabled,
            'enabled_at' => $this->enabled_at?->toIso8601String(),
            'enabled_by' => $this->relationLoaded('enabledBy') ? $this->enabledBy?->name : null,
            'workdays' => array_map('intval', $this->workdays),
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'seasons' => $this->seasons,
            'grace_minutes' => (int) $this->grace_minutes,
        ];
    }
}
