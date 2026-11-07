<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Workspace\FormBuilderController as WebFormBuilderController;
use App\Http\Requests\Forms\DuplicateFormRequest;
use App\Http\Requests\Forms\EditFormRequest;
use App\Models\Form;
use App\Queries\Forms\FormBuilderQuery;
use App\Queries\Forms\FormItems;
use App\Services\Forms\DeleteFormService;
use App\Services\Forms\DuplicateFormService;
use Illuminate\Http\JsonResponse;

/**
 * The builder over the API: the web's JSON actions as they are (draft,
 * settings, publish, close, reopen, files), and the builder's state, a copy
 * and deleting answered in JSON instead of pages.
 */
class FormBuilderController extends WebFormBuilderController
{
    public function show(EditFormRequest $request, FormBuilderQuery $query): JsonResponse
    {
        return response()->json(['message' => 'Form retrieved.', 'data' => $query->for($request->form(), $request->user())]);
    }

    public function duplicateJson(DuplicateFormRequest $request, DuplicateFormService $service): JsonResponse
    {
        $result = $service->execute($request->form(), $request->project(), $request->user());

        return $this->respondWithResult($result, $result->data instanceof Form ? FormItems::of($result->data->load('project.organization'), $request->user()) : null, 201);
    }

    public function destroyJson(EditFormRequest $request, DeleteFormService $service): JsonResponse
    {
        return $this->respondWithResult($service->execute($request->form(), $request->user()));
    }
}
