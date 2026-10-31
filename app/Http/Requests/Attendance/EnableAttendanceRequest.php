<?php

namespace App\Http\Requests\Attendance;

use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning an organization's attendance on or off: super-admins only.
 */
class EnableAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('organization') instanceof Organization
            && WorkspaceAccess::canEnableAttendance($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }
}
