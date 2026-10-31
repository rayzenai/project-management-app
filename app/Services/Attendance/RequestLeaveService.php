<?php

namespace App\Services\Attendance;

use App\Models\LeaveRequest;
use App\Services\Attendance\Concerns\SavesLeave;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Asks for leave or kaaj for oneself: pending until someone above decides
 * (DecideLeaveService). Whether the user may ask is the caller's check
 * (WorkspaceAccess::canPunch).
 */
class RequestLeaveService
{
    use SavesLeave;

    /**
     * @param  array<string, mixed>  $attributes  kind, starts_on, ends_on, reason, place
     */
    public function execute(Authenticatable $user, array $attributes): ServiceResult
    {
        $member = WorkspaceAccess::memberFor($user);

        if ($member === null) {
            return ServiceResult::failure("You aren't in an organization.", 403);
        }

        $kind = ($attributes['kind'] ?? null) === LeaveRequest::KIND_KAAJ ? 'Kaaj' : 'Leave';

        return $this->saveLeave(
            $member,
            $attributes,
            ['status' => LeaveRequest::PENDING, 'requested_by' => $user->getAuthIdentifier()],
            'You already have leave or kaaj on some of these days.',
            $kind.' requested.',
        );
    }
}
