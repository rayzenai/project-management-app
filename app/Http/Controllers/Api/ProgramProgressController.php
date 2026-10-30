<?php

namespace App\Http\Controllers\Api;

use App\Models\Program;
use App\Queries\ProgramProgressQuery;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\ProgramController::show.
 */
class ProgramProgressController extends Controller
{
    public function __invoke(Request $request, Program $program, ProgramProgressQuery $query): JsonResponse
    {
        abort_unless(WorkspaceAccess::canViewProgram($request->user(), $program), 403);

        return response()->json(['data' => $query->data($program)]);
    }
}
