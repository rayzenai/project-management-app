<?php

namespace App\Http\Resources\Attendance;

use App\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{id: int, date: string, name: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'date' => $this->date->toDateString(),
            'name' => $this->name,
        ];
    }
}
