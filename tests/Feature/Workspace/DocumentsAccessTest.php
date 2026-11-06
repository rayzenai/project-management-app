<?php

use App\Models\Document;
use App\Models\DocumentShare;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Storage;

/*
 * Who may open, edit and share a document
 * (docs/superpowers/specs/2026-11-06-documents-workspace-design.md):
 * personal documents, organization access, individual shares, frozen
 * documents, and the list's query agreeing with the rule.
 */

beforeEach(function () {
    config([
        'project-management.super_admins' => ['boss@example.com'],
        'documents.enabled' => true,
        'documents.server_url' => 'https://docs.example',
        'documents.jwt_secret' => DOCS_SECRET,
    ]);
    Storage::fake('documents');

    $this->organization = Organization::factory()->create(['name' => 'SDOC', 'slug' => 'sdoc']);
    $this->owner = docsPerson($this->organization, null);
});

/** A personal document, as the create service makes one. */
function docsPersonal(User $owner, string $title = 'Notes', array $attributes = []): Document
{
    $document = new Document(['title' => $title, 'file_type' => 'docx']);
    $document->owner_id = $owner->id;
    $document->version = 1;
    $document->editor_key = Document::newEditorKey();
    $document->size = 100;
    $document->created_by = $owner->id;
    $document->updated_by = $owner->id;
    $document->forceFill($attributes)->save();

    return $document;
}

// ---- Data ----

it('stores a personal document\'s versions under personal/', function () {
    $document = docsPersonal($this->owner);

    expect($document->isPersonal())->toBeTrue()
        ->and(DocumentVersion::pathFor($document, 1))->toBe("personal/documents/{$document->id}/v1.docx");
});

it('refuses to delete a login that owns a personal document', function () {
    docsPersonal($this->owner);

    expect(fn () => $this->owner->delete())->toThrow(QueryException::class);
});

it('keeps one share per person per document', function () {
    $document = docsPersonal($this->owner);
    $colleague = WorkspaceAccess::memberFor(docsPerson($this->organization, null));

    DocumentShare::query()->create(['document_id' => $document->id, 'member_id' => $colleague->id, 'permission' => 'view', 'shared_by' => $this->owner->id]);

    expect(fn () => DocumentShare::query()->create(['document_id' => $document->id, 'member_id' => $colleague->id, 'permission' => 'edit', 'shared_by' => $this->owner->id]))
        ->toThrow(QueryException::class);
});
