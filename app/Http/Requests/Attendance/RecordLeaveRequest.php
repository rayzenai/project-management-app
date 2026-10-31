<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\LeaveRules;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Records approved leave or kaaj for the person in the route: whoever
 * oversees them (WorkspaceAccess::canRecordLeaveFor).
 */
class RecordLeaveRequest extends FormRequest
{
    use LeaveRules;

    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof Member && WorkspaceAccess::canRecordLeaveFor($this->user(), $member);
    }
}
