<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Workspace\FormResponsesController as WebFormResponsesController;
use App\Http\Requests\Forms\FormResponsesRequest;
use App\Models\Document;
use App\Queries\Forms\FormItems;
use App\Queries\Forms\FormResponsesQuery;
use App\Services\Forms\FormExportService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;

/**
 * A form's responses over the API: the web's JSON actions and downloads as
 * they are, the first page as JSON, and Open as spreadsheet answering with
 * the new personal document.
 */
class FormResponsesController extends WebFormResponsesController
{
    public function overview(FormResponsesRequest $request, FormResponsesQuery $responses): JsonResponse
    {
        $form = $request->form()->loadMissing(['project.program', 'project.organization']);

        return response()->json(['message' => 'Responses retrieved.', 'data' => [
            'form' => [...FormItems::of($form, $request->user()), 'stages' => $form->stages(), 'languages' => $form->languages()],
            'questions' => $responses->questions($form),
            'page' => $responses->page($form, $request->filters(), $request->validated('cursor')),
        ]]);
    }

    public function spreadsheetJson(FormResponsesRequest $request, FormExportService $export): JsonResponse
    {
        abort_unless(WorkspaceAccess::canCreatePersonalDocuments($request->user()), 404);
        $result = $export->toDocument($request->form(), $request->filters(), $request->user());

        return $this->respondWithResult($result, $result->data instanceof Document ? ['id' => $result->data->id, 'title' => $result->data->title] : null, 201);
    }
}
