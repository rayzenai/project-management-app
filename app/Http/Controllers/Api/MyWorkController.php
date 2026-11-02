<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\MyWorkRequest;
use App\Queries\MyWorkQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\MyWorkController: the same payload from
 * {@see MyWorkQuery}, over every organization (the app has no switch).
 */
class MyWorkController extends Controller
{
    public function __invoke(MyWorkRequest $request, MyWorkQuery $query): JsonResponse
    {
        return response()->json($query->get($request->user(), null, $request->boolean('done')));
    }
}
