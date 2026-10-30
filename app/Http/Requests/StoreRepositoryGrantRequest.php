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
 * Gives a person read or write access to a linked repository (or sets the
 * access they have): whoever may manage its access, and only to someone
 * WorkspaceAccess::canGrantRepositoryAccessTo allows.
 */
class StoreRepositoryGrantRequest extends FormRequest
{
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
            'member_id' => ['required', 'integer', Rule::exists('members', 'id')->whereNull('deleted_at')],
            'permission' => ['required', Rule::in(GitRepositoryGrant::PERMISSIONS)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var GitRepository $repository */
            $repository = $this->route('repository');
            $member = Member::query()->find($this->integer('member_id'));

            if ($member !== null && ! WorkspaceAccess::canGrantRepositoryAccessTo($this->user(), $repository->project, $member)) {
                $validator->errors()->add('member_id', "You can't give {$member->name} access to this repository.");
            }
        });
    }
}
