<?php

namespace App\Http\Requests\Concerns;

use App\Services\Structure\SetReportsToService;
use App\Support\WorkspaceAccess;
use Illuminate\Validation\Rule;

/**
 * The person form's structure fields, shared by StoreMemberRequest and
 * UpdateMemberRequest: `designation_id` (one of the organization's
 * designations) and `reports_to_id` (a person of the organization), each
 * null to clear and left out to keep. Only whoever passes
 * WorkspaceAccess::canManageStructure for the person's organization (after
 * any move) sets them; for anyone else they are silently dropped (no rules,
 * so never in `validated()`). `reports_to_id` is applied through
 * {@see SetReportsToService}, whose refusal undoes the whole save.
 */
trait SetsMemberStructure
{
    /**
     * The organization the person is in once the request is saved.
     */
    abstract protected function structureOrganizationId(): ?int;

    /**
     * @return array<string, mixed>
     */
    protected function structureRules(): array
    {
        $organizationId = $this->structureOrganizationId();

        if (! WorkspaceAccess::canManageStructure($this->user(), $organizationId)) {
            return [];
        }

        return [
            'designation_id' => ['sometimes', 'nullable', 'integer', Rule::exists('designations', 'id')->where('organization_id', $organizationId)],
            'reports_to_id' => ['sometimes', 'nullable', 'integer', Rule::exists('members', 'id')->where('organization_id', $organizationId)->whereNull('deleted_at')],
        ];
    }

    /**
     * The designation to save with the person, when sent and allowed.
     *
     * @return array{designation_id?: int|null}
     */
    public function designation(): array
    {
        $id = $this->validated('designation_id', false);

        if ($id === false) {
            return [];
        }

        return ['designation_id' => is_numeric($id) ? (int) $id : null];
    }

    /**
     * Whether the request sets whom the person reports to.
     */
    public function setsReportsTo(): bool
    {
        return $this->validated('reports_to_id', false) !== false;
    }

    public function reportsToId(): ?int
    {
        $id = $this->validated('reports_to_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
