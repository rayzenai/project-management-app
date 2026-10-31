<?php

namespace App\Http\Requests\Attendance;

use App\Http\Requests\Attendance\Concerns\LeaveRules;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Asks for leave or kaaj for oneself: whoever may punch
 * (WorkspaceAccess::canPunch: active, in an organization with attendance on).
 */
class StoreLeaveRequest extends FormRequest
{
    use LeaveRules;

    public function authorize(): bool
    {
        return WorkspaceAccess::canPunch($this->user());
    }
}
