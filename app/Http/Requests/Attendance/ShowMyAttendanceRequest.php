<?php

namespace App\Http\Requests\Attendance;

use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The user's own day on the clock: anyone who may punch.
 */
class ShowMyAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::canPunch($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
