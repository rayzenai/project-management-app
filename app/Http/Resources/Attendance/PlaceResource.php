<?php

namespace App\Http\Resources\Attendance;

use App\Models\AttendancePlace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendancePlace
 */
class PlaceResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{id: int, name: string, latitude: float, longitude: float, radius_m: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'radius_m' => $this->radius_m,
        ];
    }
}
