<?php

namespace App\Services\Attendance;

use App\Models\LeaveRequest;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Approves or rejects a pending request, with an optional note, recording
 * who decided and when. Whether the user may decide is the caller's check
 * (WorkspaceAccess::canDecideLeave). A request decided or cancelled
 * meanwhile is refused, under a lock on its row.
 */
class DecideLeaveService
{
    public function execute(LeaveRequest $leave, Authenticatable $by, bool $approve, ?string $note = null): ServiceResult
    {
        return DB::transaction(function () use ($leave, $by, $approve, $note): ServiceResult {
            $fresh = LeaveRequest::query()->whereKey($leave->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== LeaveRequest::PENDING) {
                return ServiceResult::failure('This request was already decided or cancelled.', 422);
            }

            $note = trim((string) $note);

            $fresh->update([
                'status' => $approve ? LeaveRequest::APPROVED : LeaveRequest::REJECTED,
                'decided_by' => WorkspaceAccess::memberFor($by)?->id,
                'decided_at' => now(),
                'decision_note' => $note === '' ? null : $note,
            ]);

            return ServiceResult::success(
                $fresh->load(['member.designation', 'decider']),
                $approve ? 'Approved.' : 'Rejected.',
            );
        });
    }
}
