<?php

namespace App\Http\Requests;

use App\Models\GitRepository;
use App\Models\GitRepositoryGrant;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Takes a person's access to a linked repository away: whoever may manage
 * its access, whoever gave it and whoever the person is.
 */
class DestroyRepositoryGrantRequest extends FormRequest
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
        return [];
    }
}
