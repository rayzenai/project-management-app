<?php

namespace App\Http\Requests;

use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The sidebar's organization switch (WorkspaceOrganization): a super-admin
 * only. `organization` is an organization's id, or `all`.
 */
class SwitchOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return WorkspaceAccess::isSuperAdmin($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'organization' => $this->input('organization') === 'all'
                ? ['required']
                : ['required', 'integer', Rule::exists('organizations', 'id')],
        ];
    }

    /**
     * Null for all organizations.
     */
    public function organizationId(): ?int
    {
        return $this->input('organization') === 'all' ? null : $this->integer('organization');
    }
}
