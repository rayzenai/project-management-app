<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Documents\OrganizationAccessRequest;
use App\Http\Requests\Documents\ShareDocumentRequest;
use App\Http\Requests\Documents\StoreDocumentShareRequest;
use App\Http\Requests\Documents\UpdateDocumentShareRequest;
use App\Models\Document;
use App\Models\DocumentShare;
use App\Queries\Documents\DocumentShareCandidatesQuery;
use App\Queries\Documents\DocumentSharingQuery;
use App\Services\Documents\DocumentSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * A document's Share dialog on the web: who has it (JSON), whom to add
 * (JSON), and the changes (back with a message, as the repository access
 * panel does).
 */
class DocumentSharingController extends Controller
{
    use RedirectsWithServiceResult;

    public function show(ShareDocumentRequest $request, Document $document, DocumentSharingQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->for($document)]);
    }

    public function candidates(ShareDocumentRequest $request, Document $document, DocumentShareCandidatesQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->get($document, (string) $request->query('q', ''))]);
    }

    public function organization(OrganizationAccessRequest $request, Document $document, DocumentSharingService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->setOrganizationAccess($document, $request->access()));
    }

    public function store(StoreDocumentShareRequest $request, Document $document, DocumentSharingService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->share($document, $request->person(), (string) $request->validated('permission'), $request->user()));
    }

    public function update(UpdateDocumentShareRequest $request, Document $document, DocumentShare $share, DocumentSharingService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->changePermission($share, (string) $request->validated('permission')));
    }

    public function destroy(ShareDocumentRequest $request, Document $document, DocumentShare $share, DocumentSharingService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->unshare($share));
    }
}
