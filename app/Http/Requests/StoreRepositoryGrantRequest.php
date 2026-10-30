<?php

namespace App\Http\Requests;

use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Gives a person read or write access to a linked repository (or sets the
 * access they have): whoever may manage its access, and only to someone
 * WorkspaceAccess::canGrantRepositoryAccessTo allows. The person is looked
 * up only among the project's organization and its team, so an id from
 * anywhere else gets the same answer as an unknown one and never their
 * name.
 */
class StoreRepositoryGrantRequest extends FormRequest
{
    private ?Member $grantee = null;

    public function authorize(): bool
    {
        $repository = $this->route('repository');

        return $repository instanceof GitRepository && WorkspaceAccess::canManageRepositoryAccess($this->user(), $repository);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'member_id' => ['bail', 'required', 'integer', $this->inOrganizationOrTeam()],
            'permission' => ['required', Rule::in(GitRepositoryGrant::PERMISSIONS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var GitRepository $repository */
            $repository = $this->route('repository');

            if ($this->grantee !== null && ! WorkspaceAccess::canGrantRepositoryAccessTo($this->user(), $repository->project, $this->grantee)) {
                $validator->errors()->add('member_id', "You can't give {$this->grantee->name} access to this repository.");
            }
        });
    }

    /**
     * The person to give access to, once the request has validated.
     */
    public function grantee(): Member
    {
        return $this->grantee ?? Member::query()->findOrFail((int) $this->validated('member_id'));
    }

    /**
     * Someone of the project's organization or on its team (active or not;
     * a deleted person is unknown), the people whoever manages its access
     * can already see.
     */
    private function inOrganizationOrTeam(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            /** @var GitRepository $repository */
            $repository = $this->route('repository');
            $project = $repository->project;

            $this->grantee = Member::query()
                ->whereKey((int) $value)
                ->where(fn (Builder $q): Builder => $q
                    ->where('organization_id', $project->organization_id)
                    ->orWhereHas('teams.projects', fn (Builder $p): Builder => $p->where('projects.id', $project->getKey())))
                ->first();

            if ($this->grantee === null) {
                $fail("Choose someone from this organization or this project's team.");
            }
        };
    }
}
