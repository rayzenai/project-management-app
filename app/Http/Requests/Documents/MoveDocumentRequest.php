<?php

namespace App\Http\Requests\Documents;

use App\Http\Requests\Documents\Concerns\ChoosesUsableProject;
use App\Models\Document;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Move a personal document into a project: its owner while active
 * ({@see WorkspaceAccess::canMoveDocument()}), into a project whose documents
 * they may use. One way: a project's document never goes back.
 */
class MoveDocumentRequest extends FormRequest
{
    use ChoosesUsableProject;

    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document && WorkspaceAccess::canMoveDocument($this->user(), $document);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'project' => ['bail', 'required', 'string', 'max:255', $this->usableProject()],
        ];
    }
}
