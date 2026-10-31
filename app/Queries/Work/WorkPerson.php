<?php

namespace App\Queries\Work;

use App\Models\Member;

/**
 * How the work reports name a person: id, name and designation (from the
 * loaded `designation` relation; never queried here).
 */
final class WorkPerson
{
    /**
     * @return array{id: int, name: string, designation: ?string}
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
