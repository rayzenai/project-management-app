<?php

namespace App\Http\Requests;

use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Models\Member;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Changes a grant's permission: whoever may manage the repository's access,
 * and only for someone they may give access to now (a project manager can't
 * change an organizational admin's grant to someone off the team, only
 * remove it).
 */
class UpdateRepositoryGrantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $repository = $this->route('repository');

        return $repository instanceof GitRepository
            && $this->route('grant') instanceof GitRepositoryGrant
            && WorkspaceAccess::canManageRepositoryAccess($this->user(), $repository);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'permission' => ['required', Rule::in(GitRepositoryGrant::PERMISSIONS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var GitRepository $repository */
            $repository = $this->route('repository');
            /** @var GitRepositoryGrant $grant */
            $grant = $this->route('grant');
            $member = Member::withTrashed()->findOrFail($grant->member_id);

            if (! WorkspaceAccess::canGrantRepositoryAccessTo($this->user(), $repository->project, $member)) {
                $validator->errors()->add('permission', "You can't give {$member->name} access to this repository.");
            }
        });
    }
}
