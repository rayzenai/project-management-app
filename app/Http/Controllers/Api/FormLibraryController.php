<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Forms\CreateFormRequest;
use App\Models\Form;
use App\Queries\Forms\FormItems;
use App\Queries\Forms\FormLibraryQuery;
use App\Services\Forms\CreateFormService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** The Forms page's lists and making a form, over the API (twin of Workspace\FormLibraryController). */
class FormLibraryController extends Controller
{
    use RespondsWithServiceResult;

    public function index(Request $request, FormLibraryQuery $query): JsonResponse
    {
        abort_unless((bool) config('forms.enabled'), 404);

        return response()->json(['message' => 'Forms retrieved.', 'data' => $query->for($request->user())]);
    }

    public function store(CreateFormRequest $request, CreateFormService $service): JsonResponse
    {
        $result = $service->execute($request->project(), (string) $request->validated('title'), (string) ($request->validated('template') ?? 'blank'), $request->user());

        return $this->respondWithResult($result, $result->data instanceof Form ? FormItems::of($result->data->load('project.organization'), $request->user()) : null, 201);
    }
}
