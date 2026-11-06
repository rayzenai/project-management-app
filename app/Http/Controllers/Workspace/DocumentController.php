<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Documents\DeleteDocumentRequest;
use App\Http\Requests\Documents\DocumentRequest;
use App\Http\Requests\Documents\DocumentsRequest;
use App\Http\Requests\Documents\EditDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Project;
use App\Models\User;
use App\Queries\Documents\DocumentEditorQuery;
use App\Queries\Documents\ProjectDocumentsQuery;
use App\Services\Documents\CreateDocumentService;
use App\Services\Documents\RenameDocumentService;
use App\Services\Documents\TrashDocumentService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Support\Documents\DocumentDownload;
use App\Support\ServiceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents on the web: a project's Documents tab (its list comes with the
 * project page, ProjectShowQuery's `documents`), and one document's editor
 * page, versions, downloads, rename and trash, wherever it lives.
 */
class DocumentController extends Controller
{
    use RedirectsWithServiceResult;

    public function trash(DocumentsRequest $request, Project $project, ProjectDocumentsQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->list($project, $request->user(), trashed: true)]);
    }

    public function store(StoreDocumentRequest $request, Project $project, CreateDocumentService $service): RedirectResponse
    {
        $result = $service->blank($project, (string) $request->validated('title'), (string) $request->validated('type'), $request->user());

        return $this->openOrBack($result, $project);
    }

    public function upload(UploadDocumentRequest $request, Project $project, CreateDocumentService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->upload($project, $request->file('file'), $request->validated('title'), $request->user()));
    }

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

    /** A new blank document opens in the editor; a failure stays on the tab. */
    private function openOrBack(ServiceResult $result, Project $project): RedirectResponse
    {
        if (! $result->success || ! $result->data instanceof Document) {
            return $this->redirectWithResult($result);
        }

        return redirect()
            ->route('workspace.documents.show', ['document' => $result->data->id])
            ->with('workspace_flash', ['success' => true, 'message' => $result->message]);
    }
}
