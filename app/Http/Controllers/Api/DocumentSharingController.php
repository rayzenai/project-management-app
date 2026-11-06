<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithDocument;
use App\Http\Requests\Documents\OrganizationAccessRequest;
use App\Http\Requests\Documents\ShareDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentShareRequest;
use App\Http\Requests\Documents\UpdateDocumentShareRequest;
use App\Http\Resources\DocumentShareResource;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Queries\Documents\DocumentShareCandidatesQuery;
use App\Queries\Documents\DocumentSharingQuery;
use App\Services\Documents\DocumentSharingService;
use App\Support\ServiceResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Who has a document beyond its home, over the API: organization access and
 * chosen people.
 */
class DocumentSharingController extends Controller
{
    use RespondsWithDocument;

    public function show(ShareDocumentRequest $request, Document $document, DocumentSharingQuery $query): JsonResponse
    {
        return response()->json(['message' => 'Sharing retrieved.', 'data' => $query->for($document)]);
    }

    public function candidates(ShareDocumentRequest $request, Document $document, DocumentShareCandidatesQuery $query): JsonResponse
    {
        return response()->json(['message' => 'Candidates retrieved.', 'data' => $query->get($document, (string) $request->query('q', ''))]);
    }

    public function organization(OrganizationAccessRequest $request, Document $document, DocumentSharingService $service): JsonResponse
    {
        return $this->respondWithDocument($service->setOrganizationAccess($document, $request->access()));
    }

    public function store(StoreDocumentShareRequest $request, Document $document, DocumentSharingService $service): JsonResponse
    {
        return $this->respondWithShare($service->share($document, $request->person(), (string) $request->validated('permission'), $request->user()), 201);
    }

    public function update(UpdateDocumentShareRequest $request, Document $document, DocumentShare $share, DocumentSharingService $service): JsonResponse
    {
        return $this->respondWithShare($service->changePermission($share, (string) $request->validated('permission')));
    }

    public function destroy(ShareDocumentRequest $request, Document $document, DocumentShare $share, DocumentSharingService $service): JsonResponse
    {
        return $this->respondWithResult($service->unshare($share));
    }

    private function respondWithShare(ServiceResult $result, ?int $status = null): JsonResponse
    {
        if (! $result->success || ! $result->data instanceof DocumentShare) {
            return $this->respondWithResult($result);
        }

        return $this->respondWithResult($result, (new DocumentShareResource($result->data))->resolve(), $status);
    }
}
