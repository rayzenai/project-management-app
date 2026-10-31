<?php

namespace App\Http\Requests\Attendance;

use App\Models\LeaveRequest;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancels the request in the route: only the person it is for.
 */
class CancelLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leave = $this->route('leaveRequest');
        $self = WorkspaceAccess::memberFor($this->user());

        return $leave instanceof LeaveRequest && $self !== null && (int) $leave->member_id === (int) $self->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
