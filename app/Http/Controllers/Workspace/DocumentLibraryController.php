<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Documents\DocumentLibraryRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\Document;
use App\Models\User;
use App\Queries\Documents\DocumentLibraryQuery;
use App\Services\Documents\CreateDocumentService;
use App\Support\ServiceResult;
use App\Support\WorkspaceOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Documents page: every document the user may open, Show more, the
 * trash, and New and Upload, into a project or personal (the project tab
 * uses the same New, Upload and trash, with its project).
 */
class DocumentLibraryController extends Controller
{
    use RedirectsWithServiceResult;

    public function index(DocumentLibraryRequest $request, DocumentLibraryQuery $query): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Documents/Index', $query->page($user, $request->filters(), WorkspaceOrganization::chosen($user)));
    }

    public function list(DocumentLibraryRequest $request, DocumentLibraryQuery $query): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = $query->list($user, $request->filters(), WorkspaceOrganization::chosen($user), $request->cursor());

        return response()->json(['data' => $page['documents'], 'next_cursor' => $page['next_cursor']]);
    }

    public function trash(DocumentLibraryRequest $request, DocumentLibraryQuery $query): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $query->trash($user, $request->filters()['project'], WorkspaceOrganization::chosen($user))]);
    }

    public function store(StoreDocumentRequest $request, CreateDocumentService $service): RedirectResponse
    {
        return $this->openOrBack($service->blank($request->project(), (string) $request->validated('title'), (string) $request->validated('type'), $request->user()));
    }

    public function upload(UploadDocumentRequest $request, CreateDocumentService $service): RedirectResponse
    {
        return $this->redirectWithResult($service->upload($request->project(), $request->file('file'), $request->validated('title'), $request->user()));
    }

    /**
     * A new blank document opens in the editor, which offers its title for
     * typing over (`created_document`, this visit only); a failure stays
     * where it was.
     */
    private function openOrBack(ServiceResult $result): RedirectResponse
    {
        if (! $result->success || ! $result->data instanceof Document) {
            return $this->redirectWithResult($result);
        }

        return redirect()
            ->route('workspace.documents.show', ['document' => $result->data->id])
            ->with('workspace_flash', ['success' => true, 'message' => $result->message, 'created_document' => $result->data->id]);
    }
}
