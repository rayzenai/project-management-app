<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Documents\DeleteDocumentRequest;
use App\Http\Requests\Documents\DocumentRequest;
use App\Http\Requests\Documents\DocumentsRequest;
use App\Http\Requests\Documents\EditDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Http\Resources\DocumentResource;
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
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * JSON sibling of Workspace\DocumentController. `show` also carries the
 * signed editor config, so an app can open the editor the same way the page
 * does.
 */
class DocumentController extends Controller
{
    use RespondsWithServiceResult;

    public function index(DocumentsRequest $request, Project $project, ProjectDocumentsQuery $query): JsonResponse
    {
        return response()->json(['message' => 'Documents retrieved.', 'data' => $query->list($project, $request->user())]);
    }

    public function trash(DocumentsRequest $request, Project $project, ProjectDocumentsQuery $query): JsonResponse
    {
        return response()->json(['message' => 'Trashed documents retrieved.', 'data' => $query->list($project, $request->user(), trashed: true)]);
    }

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

    public function store(StoreDocumentRequest $request, Project $project, CreateDocumentService $service): JsonResponse
    {
        $result = $service->blank($project, (string) $request->validated('title'), (string) $request->validated('type'), $request->user());

        return $this->respondWithDocument($result, 201);
    }

    public function upload(UploadDocumentRequest $request, Project $project, CreateDocumentService $service): JsonResponse
    {
        $result = $service->upload($project, $request->file('file'), $request->validated('title'), $request->user());

        return $this->respondWithDocument($result, 201);
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

    private function respondWithDocument(ServiceResult $result, ?int $status = null): JsonResponse
    {
        $document = $result->data;

        if (! $result->success || ! $document instanceof Document) {
            return $this->respondWithResult($result);
        }

        $payload = (new DocumentResource($document->loadMissing(['creator:id,name', 'updater:id,name', 'owner:id,name', 'project.program'])))
            ->withViewer(request()->user())
            ->resolve();

        return $this->respondWithResult($result, $payload, $status);
    }
}
