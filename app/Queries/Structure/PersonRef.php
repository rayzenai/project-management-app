<?php

namespace App\Queries\Structure;

use App\Models\Member;

/**
 * How every report names a person: id, name and designation (from the
 * loaded `designation` relation; never queried here).
 */
final class PersonRef
{
    /**
     * @return array{id: int, name: string, designation: string|null}
     */
    public static function of(Member $member): array
    {
        return [
            'id' => (int) $member->getKey(),
            'name' => $member->name,
            'designation' => $member->relationLoaded('designation') ? $member->designation?->name : null,
        ];
    }
}
