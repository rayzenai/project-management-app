<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\Documents\Concerns\ChoosesUsableProject;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Make a document, blank or uploaded: in a project whose documents the
 * person may use (`project`, a slug; any other is the same 422), or, with no
 * project, a personal one ({@see WorkspaceAccess::canCreatePersonalDocuments()}).
 * `project()` is where it goes once validated (null: personal).
 */
abstract class CreateDocumentRequest extends FormRequest
{
    use ChoosesUsableProject;

    public function authorize(): bool
    {
        if (! config('documents.enabled') || $this->user() === null) {
            return false;
        }

        return $this->filled('project') || WorkspaceAccess::canCreatePersonalDocuments($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project' => ['bail', 'nullable', 'string', 'max:255', $this->usableProject()],
        ];
    }
}
