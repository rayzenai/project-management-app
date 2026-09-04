<?php

namespace App\Http\Controllers\Api;

use App\Queries\HomeQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\HomeController. Delegates to the shared
 * {@see HomeQuery} so the date bucketing and the `mine` / `all` scope rule stay
 * identical across the Inertia web view and this API feed.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, HomeQuery $query): JsonResponse
    {
        return response()->json($query->get($request));
    }
}
