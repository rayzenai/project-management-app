<?php

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Models\Member;
use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Who fills a form in. The chosen people must be active people of its
 * project's organization (one generic message otherwise, naming nobody);
 * changing the other organizations is for whoever passes
 * canAddFormOrganizations, and refused for anyone else.
 */
class UpdateFormRespondentsRequest extends EditFormRequest
{
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        if (! $this->has('organization_ids') || WorkspaceAccess::canAddFormOrganizations($this->user(), $this->form())) {
            return true;
        }

        $current = $this->form()->respondentOrganizations()->pluck('organizations.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $asked = collect((array) $this->input('organization_ids'))->map(fn ($id): int => (int) $id)->sort()->values()->all();

        return $current === $asked;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'respondents' => ['required', Rule::in(Form::RESPONDENTS)],
            'member_ids' => ['present_if:respondents,people', 'array', 'max:2000'],
            'member_ids.*' => ['integer'],
            'organization_ids' => ['sometimes', 'array', 'max:200'],
            'organization_ids.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $ids = $this->memberIds();
            $found = Member::query()->active()->whereIn('id', $ids)->where('organization_id', $this->form()->project->organization_id)->count();

            if ($found !== count($ids)) {
                $validator->errors()->add('member_ids', 'Choose people of '.($this->form()->project->organization->name ?? 'the organization').'.');
            }

            if ($this->input('respondents') === Form::RESPONDENTS_PEOPLE && $ids === []) {
                $validator->errors()->add('member_ids', 'Choose at least one person.');
            }

            $organizations = $this->organizationIds();

            if ($organizations !== null && Organization::query()->whereIn('id', $organizations)->count() !== count($organizations)) {
                $validator->errors()->add('organization_ids', 'Choose organizations from the list.');
            }
        });
    }

    /** @return list<int> */
    public function memberIds(): array
    {
        return array_values(array_unique(array_map('intval', (array) $this->input('member_ids', []))));
    }

    /** @return list<int>|null null: unchanged */
    public function organizationIds(): ?array
    {
        return $this->has('organization_ids') ? array_values(array_unique(array_map('intval', (array) $this->input('organization_ids')))) : null;
    }
}
