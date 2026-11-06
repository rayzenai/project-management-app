<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithDocument;
use App\Http\Requests\Documents\DocumentLibraryRequest;
use App\Http\Requests\Documents\StoreDocumentRequest;
use App\Http\Requests\Documents\UploadDocumentRequest;
use App\Models\User;
use App\Queries\Documents\DocumentLibraryQuery;
use App\Services\Documents\CreateDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Every document the user may open (the app's Documents page), the trash,
 * and making a document in a project or a personal one.
 */
class DocumentLibraryController extends Controller
{
    use RespondsWithDocument;

    public function index(DocumentLibraryRequest $request, DocumentLibraryQuery $query): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = $query->list($user, $request->filters(), null, $request->cursor());

        return response()->json(['message' => 'Documents retrieved.', 'data' => $page['documents'], 'next_cursor' => $page['next_cursor']]);
    }

    public function trash(DocumentLibraryRequest $request, DocumentLibraryQuery $query): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['message' => 'Trashed documents retrieved.', 'data' => $query->trash($user, $request->filters()['project'], null)]);
    }

    public function store(StoreDocumentRequest $request, CreateDocumentService $service): JsonResponse
    {
        return $this->respondWithDocument($service->blank($request->project(), (string) $request->validated('title'), (string) $request->validated('type'), $request->user()), 201);
    }

    public function upload(UploadDocumentRequest $request, CreateDocumentService $service): JsonResponse
    {
        return $this->respondWithDocument($service->upload($request->project(), $request->file('file'), $request->validated('title'), $request->user()), 201);
    }
}
