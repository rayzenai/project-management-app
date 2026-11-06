<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rename a document: Can edit ({@see WorkspaceAccess::documentAccess()}),
 * never a frozen one (the route leaves trashed documents out).
 */
class EditDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document
            && ! WorkspaceAccess::isDocumentFrozen($document)
            && WorkspaceAccess::documentAccess($this->user(), $document) === Document::ACCESS_EDIT;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.Document::TITLE_MAX],
        ];
    }
}
