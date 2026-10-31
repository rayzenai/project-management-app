<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceNetwork;
use App\Models\AttendancePlace;
use App\Models\Holiday;
use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A change to an organization's attendance settings: whoever administers
 * the organization in the route. A place, network or holiday in the route
 * must be that organization's, so nobody reaches another organization's
 * records by putting their id under their own organization. Removing one
 * uses this as it is; the saving requests add their rules.
 */
class ManageAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $organization = $this->route('organization');

        if (! $organization instanceof Organization
            || ! WorkspaceAccess::canManageAttendance($this->user(), $organization->id)) {
            return false;
        }

        foreach (['place', 'network', 'holiday'] as $parameter) {
            $record = $this->route($parameter);

            if ($record instanceof AttendancePlace || $record instanceof AttendanceNetwork || $record instanceof Holiday) {
                if ((int) $record->organization_id !== (int) $organization->id) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    protected function organizationId(): int
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization ? (int) $organization->id : 0;
    }
}
