<?php

namespace App\Queries\Structure;

use App\Models\Member;
use App\Support\OrgChart;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The people of an organization a viewer's lists show: the attendance
 * register, today's work, a team's week. Its admins and super-admins see
 * everyone; anyone else the people below them. With `$under`, that person
 * and everyone below them (the caller's FormRequest checks
 * `WorkspaceAccess::canViewPersonOf($viewer, $under)` first). Active people
 * only, by name, with their designation loaded. Every such list starts here.
 */
final class PeopleInViewQuery
{
    /**
     * @return Collection<int, Member>
     */
    public function run(Authenticatable $viewer, int $organizationId, ?Member $under = null): Collection
    {
        $ids = $under !== null
            ? [(int) $under->getKey(), ...OrgChart::descendantIds($under)]
            : WorkspaceAccess::overseenMemberIds($viewer, $organizationId);

        return Member::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids ?? []))
            ->with('designation:id,name,rank')
            ->orderBy('name')
            ->get();
    }
}
