<?php

namespace App\Http\Resources\Attendance;

use App\Models\LeaveRequest;
use App\Queries\Attendance\LeaveQuery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The leave and kaaj page as LeaveQuery reads it: whether the user may ask,
 * their requests, those waiting for their decision, and the people they
 * may record leave for.
 *
 * @property array{can_ask: bool, mine: Collection<int, LeaveRequest>, to_decide: Collection<int, LeaveRequest>, can_record_for: list<array{id: int, name: string, designation: string|null, organization: string}>} $resource
 *
 * @see LeaveQuery::run()
 */
class LeaveOverviewResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'can_ask' => $this->resource['can_ask'],
            'mine' => LeaveRequestResource::collection($this->resource['mine'])->resolve($request),
            'to_decide' => LeaveRequestResource::collection($this->resource['to_decide'])->resolve($request),
            'can_record_for' => $this->resource['can_record_for'],
        ];
    }
}
