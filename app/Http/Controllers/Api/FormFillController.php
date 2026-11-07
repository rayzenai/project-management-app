<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Workspace\FormFillController as WebFormFillController;
use App\Http\Requests\Forms\SeeFormRequest;
use App\Queries\Forms\FormFillQuery;
use Illuminate\Http\JsonResponse;

/**
 * Filling a form in over the API: the form to fill (by id; the app opens a
 * link's code through the Forms list), and the web's draft, Clear and
 * Submit as they are.
 */
class FormFillController extends WebFormFillController
{
    public function show(SeeFormRequest $request, FormFillQuery $query): JsonResponse
    {
        return response()->json(['message' => 'Form retrieved.', 'data' => $query->for($request->form(), $request->user(), false)]);
    }
}
