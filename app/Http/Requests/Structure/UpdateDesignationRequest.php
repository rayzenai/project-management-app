<?php

namespace App\Http\Requests\Structure;

use App\Models\Designation;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Renaming or re-ranking a designation: whoever administers its
 * organization. Either field may be left out.
 */
class UpdateDesignationRequest extends FormRequest
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
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'rank' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.StoreDesignationRequest::MAX_RANK],
        ];
    }

    /**
     * What to change: the name, the rank, or both (an empty rank keeps it).
     *
     * @return array{name?: string, rank?: int}
     */
    public function changes(): array
    {
        $changes = [];

        if ($this->has('name')) {
            $changes['name'] = $this->string('name')->trim()->value();
        }

        if ($this->filled('rank')) {
            $changes['rank'] = $this->integer('rank');
        }

        return $changes;
    }
}
