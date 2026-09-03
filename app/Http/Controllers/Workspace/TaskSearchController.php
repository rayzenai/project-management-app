<?php

namespace App\Http\Controllers\Workspace;

use App\Queries\TaskSearchQuery;
use App\Support\ApiResponser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON-only fuzzy search used by the My Workspace command palette. Searches
 * tasks, notes, and contacts across every project and returns them grouped
 * so the UI can render three columns. Task-anchored note/contact hits navigate
 * to the parent task; the user's personal sticky notes share the Notes column
 * and open the notes board instead.
 */
class TaskSearchController extends Controller
{
    use ApiResponser;

    public function __invoke(Request $request): JsonResponse
    {
        return $this->dataResponse((new TaskSearchQuery)->get($request));
    }
}
