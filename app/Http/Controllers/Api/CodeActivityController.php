<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\CodeActivityRequest;
use App\Queries\CodeActivityQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\CodeActivityController.
 */
class CodeActivityController extends Controller
{
    public function index(CodeActivityRequest $request, CodeActivityQuery $query): JsonResponse
    {
        return response()->json([
            'data' => $query->get($request->user(), $request->filters()),
            'filters' => $request->appliedFilters(),
        ]);
    }

    /**
     * Someone outside the scope, or no one, is an empty list.
     */
    public function person(CodeActivityRequest $request, string $user, CodeActivityQuery $query): JsonResponse
    {
        return response()->json(['data' => $query->commitsOf($request->user(), (int) $user, $request->filters())]);
    }
}
