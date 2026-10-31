<?php

namespace App\Queries\Structure;

use App\Http\Resources\DesignationResource;
use App\Models\Designation;
use App\Models\Member;
use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An organization's organogram, for the page (outline and chart) and the
 * API: its designations, most senior first, each with how many active
 * people hold it; and its active people, each with their designation, whom
 * they report to and how many report to them directly. A higher authority
 * who is not an active person of the organization counts as none, so their
 * people show at the top. Names, designations and lines only, never emails.
 * The caller's FormRequest checks WorkspaceAccess::canViewOrganogram first.
 */
final class OrganogramQuery
{
    /**
     * Null when there is no such organization.
     *
     * @return array{
     *     organization: array{id: int, name: string},
     *     designations: list<array{id: int, organization_id: int, name: string, rank: int, people_count: int}>,
     *     people: list<array{id: int, name: string, designation_id: int|null, designation: string|null, reports_to_id: int|null, is_office_admin: bool, direct_reports_count: int}>,
     *     can_manage: bool,
     * }|null
     */
    public function get(Authenticatable $viewer, int $organizationId): ?array
    {
        $organization = Organization::query()->find($organizationId, ['id', 'name']);

        if ($organization === null) {
            return null;
        }

        $designations = Designation::query()
            ->where('organization_id', $organizationId)
            ->withCount(['members as people_count' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('rank')
            ->orderBy('name')
            ->get();

        $members = Member::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->with('designation:id,name,rank')
            ->get(['id', 'name', 'designation_id', 'reports_to_id', 'is_office_admin'])
            ->sort(fn (Member $a, Member $b): int => [$a->designation->rank ?? PHP_INT_MAX, mb_strtolower($a->name)]
                <=> [$b->designation->rank ?? PHP_INT_MAX, mb_strtolower($b->name)])
            ->values();

        $present = $members->pluck('id')->flip();
        $bossOf = fn (Member $member): ?int => $member->reports_to_id !== null && $present->has($member->reports_to_id)
            ? (int) $member->reports_to_id
            : null;
        $directReports = $members->map($bossOf)->filter()->countBy();

        return [
            'organization' => ['id' => (int) $organization->id, 'name' => $organization->name],
            'designations' => array_values(DesignationResource::collection($designations)->resolve()),
            'people' => array_values($members->map(fn (Member $member): array => [
                'id' => (int) $member->id,
                'name' => $member->name,
                'designation_id' => $member->designation_id,
                'designation' => $member->designation?->name,
                'reports_to_id' => $bossOf($member),
                'is_office_admin' => (bool) $member->is_office_admin,
                'direct_reports_count' => (int) $directReports->get($member->id, 0),
            ])->all()),
            'can_manage' => WorkspaceAccess::canManageStructure($viewer, $organizationId),
        ];
    }
}
