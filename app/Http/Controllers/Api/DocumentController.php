<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithDocument;
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
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON sibling of Workspace\DocumentController. `show` also carries the
 * signed editor config, so an app can open the editor the same way the page
 * does.
 */
class DocumentController extends Controller
{
    use RespondsWithDocument;

    public function show(DocumentRequest $request, Document $document, ProjectDocumentsQuery $documents, DocumentEditorQuery $editor): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'message' => 'Document retrieved.',
            'data' => [
                ...$documents->show($document, $user),
                'editor' => $editor->for($document, $user, (string) $request->access()),
            ],
        ]);
    }

    public function versions(DocumentRequest $request, Document $document, ProjectDocumentsQuery $query): JsonResponse
    {
        return response()->json(['message' => 'Versions retrieved.', 'data' => $query->show($document, $request->user())['versions']]);
    }

    public function download(DocumentRequest $request, Document $document, DocumentVersion $version): StreamedResponse
    {
        return DocumentDownload::of($document, $version);
    }

    public function update(EditDocumentRequest $request, Document $document, RenameDocumentService $service): JsonResponse
    {
        return $this->respondWithDocument($service->execute($document, (string) $request->validated('title'), $request->user()));
    }

    public function destroy(DeleteDocumentRequest $request, Document $document, TrashDocumentService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($document, $request->user()));
    }

    public function restore(DeleteDocumentRequest $request, Document $document, RestoreWorkspaceModel $service): JsonResponse
    {
        return $this->respondWithDocument($service->execute($document));
    }

    public function move(MoveDocumentRequest $request, Document $document, MoveDocumentService $service): JsonResponse
    {
        /** @var Project $project validated */
        $project = $request->project();

        return $this->respondWithDocument($service->execute($document, $project));
    }
}
