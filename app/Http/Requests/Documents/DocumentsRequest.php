<?php

namespace App\Http\Requests\Documents;

use App\Models\Project;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A project's documents: listing them, opening one, its versions and their
 * downloads. Whoever may use the project's documents
 * ({@see WorkspaceAccess::canUseProjectDocuments()}). The writes extend it.
 */
class DocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && WorkspaceAccess::canUseProjectDocuments($this->user(), $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
