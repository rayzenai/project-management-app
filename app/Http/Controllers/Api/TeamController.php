<?php

namespace App\Http\Controllers\Api;

use App\Queries\TeamIndexQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\TeamController: the people directory and the
 * project teams the caller may see. Teams are made with their projects, so
 * there is nothing to create here.
 */
class TeamController extends Controller
{
    public function index(Request $request, TeamIndexQuery $query): JsonResponse
    {
        return response()->json($query->data($request));
    }
}
