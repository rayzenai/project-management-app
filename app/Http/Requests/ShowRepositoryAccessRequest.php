<?php

namespace App\Http\Requests;

use App\Models\GitRepository;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Who reaches a linked repository, and the people who could be given
 * access to it: whoever may manage its access.
 */
class ShowRepositoryAccessRequest extends FormRequest
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
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
