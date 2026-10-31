<?php

namespace App\Http\Requests\Structure;

use App\Models\Member;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Choosing whom a person reports to (null: no one): whoever administers the
 * person's organization, since the lines decide who sees whose attendance,
 * location and work. Who may be chosen is SetReportsToService's check
 * (same organization, active, no loop), answered as a 422.
 */
class SetReportsToRequest extends FormRequest
{
    public function authorize(): bool
    {
        $member = $this->route('member');

        return $member instanceof Member
            && WorkspaceAccess::canManageStructure($this->user(), $member->organization_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reports_to_id' => ['present', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function bossId(): ?int
    {
        $id = $this->validated('reports_to_id');

        return is_numeric($id) ? (int) $id : null;
    }
}
