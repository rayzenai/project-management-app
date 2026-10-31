<?php

namespace App\Http\Requests\Attendance;

use App\Models\Organization;
use App\Queries\Attendance\AttendanceSettingsQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An organization's attendance settings: whoever administers it. The API
 * names the organization in the route; the web page takes `?organization=`
 * (a super-admin's switcher) and otherwise opens the viewer's own.
 */
class ShowAttendanceSettingsRequest extends FormRequest
{
    private ?Organization $organization = null;

    public function authorize(AttendanceSettingsQuery $query): bool
    {
        $routed = $this->route('organization');

        $this->organization = $routed instanceof Organization
            ? $routed
            : $query->organizationFor($this->user(), $this->integer('organization') ?: null);

        return $this->organization !== null
            && WorkspaceAccess::canManageAttendance($this->user(), $this->organization->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function organization(): Organization
    {
        return $this->organization ?? abort(403);
    }
}
