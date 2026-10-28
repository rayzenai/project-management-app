<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('organization') instanceof Organization
            && WorkspaceAccess::canManageOrganizations($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Organization $organization */
        $organization = $this->route('organization');

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('organizations', 'name')->ignore($organization->getKey())],
        ];
    }
}
