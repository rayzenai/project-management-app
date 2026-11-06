<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Models\Organization;
use App\Support\WorkspaceAccess;
use Illuminate\Foundation\Http\FormRequest;

/**
 * See and change who has a document: its organization access and shares
 * ({@see WorkspaceAccess::canShareDocument()}). The routes leave trashed
 * documents out.
 */
class ShareDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('document');

        return $document instanceof Document && WorkspaceAccess::canShareDocument($this->user(), $document);
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

    /** "SDOC", for messages about the document's organization. */
    protected function organizationName(): ?string
    {
        $organizationId = WorkspaceAccess::documentOrganizationId($this->document());

        return $organizationId === null ? null : Organization::query()->whereKey($organizationId)->value('name');
    }
}
