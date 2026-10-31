<?php

namespace App\Services\Attendance;

use App\Models\LeaveRequest;
use App\Support\ServiceResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancels one's own leave or kaaj while it is pending, or approved and not
 * started yet. Whose it is is the caller's check.
 */
class CancelLeaveService
{
    public function execute(LeaveRequest $leave): ServiceResult
    {
        return DB::transaction(function () use ($leave): ServiceResult {
            $fresh = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->first();

            if ($fresh === null || ! self::cancellable($fresh)) {
                return ServiceResult::failure("Only a pending request, or approved leave or kaaj that hasn't started, can be cancelled.", 422);
            }

            $fresh->update(['status' => LeaveRequest::CANCELLED]);

            return ServiceResult::success($fresh->load(['member.designation', 'decider']), 'Cancelled.');
        });
    }

    /** Pending, or approved and starting after today. */
    public static function cancellable(LeaveRequest $leave): bool
    {
        return $leave->status === LeaveRequest::PENDING
            || ($leave->status === LeaveRequest::APPROVED && $leave->starts_on->gt(CarbonImmutable::today()));
    }
}
