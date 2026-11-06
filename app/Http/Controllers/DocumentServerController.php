<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\Documents\SaveEditedDocumentService;
use App\Support\Documents\DocumentTypes;
use App\Support\Documents\EditorToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two calls ONLYOFFICE makes to the tracker, server to server, with no
 * session: fetching a document's file and reporting a save. Each needs the
 * route's signature (the editor config handed out both URLs) and the
 * editor's own JWT for that operation. Looked up here, not by route binding
 * (these routes run without the web middleware).
 */
class DocumentServerController extends Controller
{
    public function file(Request $request, int $document, int $version): StreamedResponse
    {
        abort_if(EditorToken::payloadFrom($request, EditorToken::OPERATION_DOWNLOAD) === null, 403);

        $found = Document::query()->find($document);
        abort_if($found === null, 404);

        $file = DocumentVersion::query()->where('document_id', $found->id)->where('version', $version)->first();
        abort_if($file === null, 404);

        return Storage::disk((string) config('documents.disk'))->response($file->path, $found->fileName(), [
            'Content-Type' => DocumentTypes::mime($found->file_type),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** ONLYOFFICE reads `{"error": 0}` as done; anything else, it asks again. */
    public function callback(Request $request, int $document, SaveEditedDocumentService $service): JsonResponse
    {
        $payload = EditorToken::payloadFrom($request, EditorToken::OPERATION_CALLBACK);

        if ($payload === null) {
            return response()->json(['error' => 1, 'message' => 'Not signed by the editor.'], 403);
        }

        $found = Document::withTrashed()->find($document);

        // Gone with its project: nothing left to save into.
        if ($found === null) {
            return response()->json(['error' => 0]);
        }

        return response()->json(['error' => $service->execute($found, $payload)->success ? 0 : 1]);
    }
}
