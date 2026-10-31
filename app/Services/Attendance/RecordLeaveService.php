<?php

namespace App\Services\Attendance;

use App\Models\LeaveRequest;
use App\Models\Member;
use App\Services\Attendance\Concerns\SavesLeave;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Records approved leave or kaaj for someone directly, by someone who
 * oversees them (the caller's check: WorkspaceAccess::canRecordLeaveFor).
 * The same rules as asking; the recorder is its decider.
 */
class RecordLeaveService
{
    use SavesLeave;

    /**
     * @param  array<string, mixed>  $attributes  kind, starts_on, ends_on, reason, place
     */
    public function execute(Member $member, Authenticatable $by, array $attributes): ServiceResult
    {
        $kind = ($attributes['kind'] ?? null) === LeaveRequest::KIND_KAAJ ? 'Kaaj' : 'Leave';

        return $this->saveLeave(
            $member,
            $attributes,
            [
                'status' => LeaveRequest::APPROVED,
                'requested_by' => $by->getAuthIdentifier(),
                'decided_by' => WorkspaceAccess::memberFor($by)?->id,
                'decided_at' => now(),
            ],
            $member->name.' already has leave or kaaj on some of these days.',
            $kind.' recorded for '.$member->name.'.',
        );
    }
}
