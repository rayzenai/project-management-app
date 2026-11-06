<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Move a document to the trash, or bring it back
 * ({@see WorkspaceAccess::canDeleteDocument()}).
 */
class DeleteDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document && WorkspaceAccess::canDeleteDocument($this->user(), $document);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
