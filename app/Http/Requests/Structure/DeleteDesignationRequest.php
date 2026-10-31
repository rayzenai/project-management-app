<?php

namespace App\Http\Requests\Structure;

use App\Models\Designation;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a designation: whoever administers its organization.
 */
class DeleteDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $designation = $this->route('designation');

        return $designation instanceof Designation
            && WorkspaceAccess::canManageStructure($this->user(), $designation->organization_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
