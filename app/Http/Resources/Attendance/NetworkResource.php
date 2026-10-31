<?php

namespace App\Http\Resources\Attendance;

use App\Models\AttendanceNetwork;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceNetwork
 */
class NetworkResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{id: int, label: string, cidr: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'label' => $this->label,
            'cidr' => $this->cidr,
        ];
    }
}
