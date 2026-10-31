<?php

namespace App\Http\Resources;

use App\Models\Designation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of an organization's designations. `people_count` (active people who
 * hold it) only when the query counted it.
 *
 * @mixin Designation
 */
class DesignationResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'organization_id' => (int) $this->organization_id,
            'name' => $this->name,
            'rank' => (int) $this->rank,
            'people_count' => $this->whenHas('people_count', fn (): int => (int) $this->resource->getAttribute('people_count')),
        ];
    }
}
