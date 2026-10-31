<?php

namespace App\Http\Requests\Structure;

use App\Models\Member;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Choosing a person's designation (null: none), from the organogram: the
 * same people who choose whom they report to (SetReportsToRequest). Which
 * designation may be chosen is SetDesignationService's check (one of the
 * person's organization's), answered as a 422.
 */
class SetDesignationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof Member
            && WorkspaceAccess::canManageStructure($this->user(), $member->organization_id)
            && WorkspaceAccess::canManageMember($this->user(), $member);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'designation_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function designationId(): ?int
    {
        $id = $this->validated('designation_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
