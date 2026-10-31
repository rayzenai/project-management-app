<?php

namespace App\Queries\Attendance;

use App\Models\LeaveRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Queries\Structure\PeopleInViewQuery;
use App\Queries\Structure\PersonRef;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The leave and kaaj page: the user's own requests, the pending requests
 * of people they oversee (WorkspaceAccess::overseenMemberIds, the twin of
 * canDecideLeave; every organization for a super-admin), and the people
 * they may record leave or kaaj for (PeopleInViewQuery).
 */
final class LeaveQuery
{
    /** How far back "my requests" reach, in days (upcoming ones always show). */
    public const MINE_DAYS = 60;

    public function __construct(
        private readonly PeopleInViewQuery $people,
        private readonly OrganizationChoicesQuery $organizations,
    ) {}

    /**
     * @return array{can_ask: bool, mine: Collection<int, LeaveRequest>, to_decide: Collection<int, LeaveRequest>, can_record_for: list<array{id: int, name: string, designation: string|null, organization: string}>}
     */
    public function run(Authenticatable $user): array
    {
        $self = WorkspaceAccess::memberFor($user);

        return [
            'can_ask' => WorkspaceAccess::canPunch($user),
            'mine' => $this->mine($self),
            'to_decide' => $this->toDecide($user, $self),
            'can_record_for' => $this->canRecordFor($user, $self),
        ];
    }

    /**
     * Upcoming requests and those of the last 60 days, newest first.
     *
     * @return Collection<int, LeaveRequest>
     */
    private function mine(?Member $self): Collection
    {
        if ($self === null) {
            return new Collection;
        }

        return LeaveRequest::query()
            ->where('member_id', $self->id)
            ->whereDate('ends_on', '>=', CarbonImmutable::today()->subDays(self::MINE_DAYS)->toDateString())
            ->with('decider:id,name')
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Pending requests of the people the user oversees, soonest first;
     * never their own.
     *
     * @return Collection<int, LeaveRequest>
     */
    private function toDecide(Authenticatable $user, ?Member $self): Collection
    {
        $query = LeaveRequest::query()->where('status', LeaveRequest::PENDING);

        if (! WorkspaceAccess::isSuperAdmin($user)) {
            if ($self === null || ! $self->is_active || $self->organization_id === null) {
                return new Collection;
            }

            $organizationId = (int) $self->organization_id;
            $ids = WorkspaceAccess::overseenMemberIds($user, $organizationId);

            if ($ids === []) {
                return new Collection;
            }

            $query->whereHas('member', fn (Builder $member) => $member
                ->where('organization_id', $organizationId)
                ->when($ids !== null, fn (Builder $member) => $member->whereIn('id', $ids ?? [])));
        }

        return $query
            ->when($self !== null, fn (Builder $query) => $query->where('member_id', '!=', $self?->id))
            ->whereHas('member')
            ->with(['member:id,name,organization_id,designation_id', 'member.designation:id,name'])
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * People below the user in an organization with attendance on (every
     * such organization for a super-admin), never themselves.
     *
     * @return list<array{id: int, name: string, designation: string|null, organization: string}>
     */
    private function canRecordFor(Authenticatable $user, ?Member $self): array
    {
        $organizations = match (true) {
            WorkspaceAccess::isSuperAdmin($user) => $this->organizations->options($user, withAttendance: true),
            $self?->organization !== null && $self->is_active && WorkspaceAccess::attendanceEnabled($self->organization_id) => [['id' => (int) $self->organization_id, 'name' => $self->organization->name]],
            default => [],
        };

        $people = [];

        foreach ($organizations as $organization) {
            foreach ($this->people->run($user, $organization['id']) as $member) {
                if ($self === null || ! $member->is($self)) {
                    $people[] = [...PersonRef::of($member), 'organization' => $organization['name']];
                }
            }
        }

        return $people;
    }
}
