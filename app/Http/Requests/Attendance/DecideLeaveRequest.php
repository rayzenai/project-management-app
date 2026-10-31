<?php

namespace App\Http\Requests\Attendance;

use App\Models\LeaveRequest;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Approves or rejects the request in the route, with an optional note:
 * whoever oversees its person, never they themselves
 * (WorkspaceAccess::canDecideLeave).
 */
class DecideLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        $leave = $this->route('leaveRequest');

        return $leave instanceof LeaveRequest && WorkspaceAccess::canDecideLeave($this->user(), $leave);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) ? $note : null;
    }
}
