<?php

namespace App\Http\Requests\Structure;

use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding a designation to an organization's list: whoever administers it
 * (WorkspaceAccess::canManageStructure). A name already on the list, in
 * any case, is CreateDesignationService's 422.
 */
class StoreDesignationRequest extends FormRequest
{
    public const MAX_RANK = 32767;

    public function authorize(): bool
    {
        $organization = $this->route('organization');

        return $organization instanceof Organization
            && WorkspaceAccess::canManageStructure($this->user(), $organization->getKey());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'rank' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_RANK],
        ];
    }

    /**
     * @return array{name: string, rank: int|null}
     */
    public function designation(): array
    {
        return [
            'name' => $this->string('name')->trim()->value(),
            'rank' => $this->filled('rank') ? $this->integer('rank') : null,
        ];
    }
}
