<?php

namespace App\Http\Requests\Documents;

use App\Models\Document;
use App\Support\WorkspaceAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Open a document to everyone in its organization (`access` view or edit)
 * or close it (null). A document with no organization (a super-admin's own,
 * with no person) has nobody to open to.
 */
class OrganizationAccessRequest extends ShareDocumentRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'access' => ['present', 'nullable', Rule::in(Document::ACCESSES)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (WorkspaceAccess::documentOrganizationId($this->document()) === null) {
                $validator->errors()->add('access', 'This document has no organization to share with.');
            }
        });
    }

    public function access(): ?string
    {
        $access = $this->validated('access');

        return $access === null ? null : (string) $access;
    }
}
