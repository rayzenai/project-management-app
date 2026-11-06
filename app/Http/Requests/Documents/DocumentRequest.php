<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Open a document (the editor page, its versions and downloads): anyone
 * whose access level is not null ({@see WorkspaceAccess::documentAccess()}).
 * A trashed document is out of the editor (404), except a frozen one, which
 * stays readable for super-admins as the record its owner left.
 */
class DocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->document();

        abort_if($document->trashed() && ! WorkspaceAccess::isDocumentFrozen($document), 404);

        return $this->access() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function document(): Document
    {
        $document = $this->route('document');
        abort_unless($document instanceof Document, 404);

        return $document;
    }

    /** The viewer's level: Document::ACCESS_EDIT or ACCESS_VIEW, else null. */
    public function access(): ?string
    {
        return WorkspaceAccess::documentAccess($this->user(), $this->document());
    }
}
