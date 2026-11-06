<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Documents\DeleteDocumentRequest;
use App\Http\Requests\Documents\DocumentRequest;
use App\Http\Requests\Documents\EditDocumentRequest;
use App\Http\Requests\Documents\MoveDocumentRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\User;
use App\Queries\Documents\DocumentEditorQuery;
use App\Queries\Documents\ProjectDocumentsQuery;
use App\Services\Documents\MoveDocumentService;
use App\Services\Documents\RenameDocumentService;
use App\Services\Documents\TrashDocumentService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Support\Documents\DocumentDownload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One document on the web, wherever it lives: its editor page, versions,
 * downloads, rename, trash and restore. The lists, New and Upload are
 * DocumentLibraryController's.
 */
class DocumentController extends Controller
{
    use RedirectsWithServiceResult;

    public function show(DocumentRequest $request, Document $document, ProjectDocumentsQuery $documents, DocumentEditorQuery $editor): Response
    {
        /** @var User $user */
        $user = $request->user();

        // The page carries a signed editor config: kept in history only
        // encrypted, unreadable once its person signs out (AuthController).
        Inertia::encryptHistory();

        return Inertia::render('Documents/Edit', [
            ...$documents->show($document, $user),
            'editor' => $editor->for($document, $user, (string) $request->access()),
        ]);
    }

    public function versions(DocumentRequest $request, Document $document, ProjectDocumentsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->show($document, $request->user())['versions']]);
    }

    public function download(DocumentRequest $request, Document $document, DocumentVersion $version): StreamedResponse
    {
        return DocumentDownload::of($document, $version);
    }

    public function update(EditDocumentRequest $request, Document $document, RenameDocumentService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($document, (string) $request->validated('title'), $request->user()));
    }

    public function destroy(DeleteDocumentRequest $request, Document $document, TrashDocumentService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($document, $request->user()));
    }

    public function restore(DeleteDocumentRequest $request, Document $document, RestoreWorkspaceModel $service): RedirectResponse
    {
        return $this->redirectWithResult($service->execute($document));
    }

    public function move(MoveDocumentRequest $request, Document $document, MoveDocumentService $service): RedirectResponse
    {
        /** @var Project $project validated */
        $project = $request->project();

        return $this->redirectWithResult($service->execute($document, $project));
    }
}
