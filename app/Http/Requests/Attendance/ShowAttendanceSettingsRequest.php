<?php

namespace App\Http\Requests\Attendance;

use App\Models\Organization;
use App\Queries\Structure\OrganizationChoicesQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An organization's attendance settings: whoever administers it. The API
 * names the organization in the route; the web page takes `?organization=`
 * (a super-admin's switcher) and otherwise opens OrganizationChoicesQuery's
 * default.
 */
class ShowAttendanceSettingsRequest extends FormRequest
{
    private ?int $organizationId = null;

    public function authorize(OrganizationChoicesQuery $organizations): bool
    {
        $routed = $this->route('organization');

        $this->organizationId = match (true) {
            $routed instanceof Organization => (int) $routed->getKey(),
            $this->integer('organization') > 0 => $this->integer('organization'),
            default => $organizations->defaultId($this->user()),
        };

        return $this->organizationId !== null
            && WorkspaceAccess::canManageAttendance($this->user(), $this->organizationId);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function organizationId(): int
    {
        return (int) $this->organizationId;
    }
}
