<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreProgramRequest;
use App\Http\Requests\UpdateProgramRequest;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use App\Services\Workspace\CreateProgramService;
use App\Services\Workspace\DeleteProgramService;
use App\Services\Workspace\UpdateProgramService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of Workspace\ProgramController.
 */
class ProgramController extends Controller
{
    use RespondsWithServiceResult;

    public function index(Request $request): JsonResponse
    {
        $programs = Program::query()
            ->visibleTo($request->user())
            ->with(['organization', 'manager'])
            ->withCount('projects')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => ProgramResource::collection($programs)->resolve($request)]);
    }

    public function store(StoreProgramRequest $request, CreateProgramService $service): JsonResponse
    {
        $result = $service->execute($request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Program ? new ProgramResource($result->data) : null,
            $result->success ? 201 : null,
        );
    }

    public function update(UpdateProgramRequest $request, Program $program, UpdateProgramService $service): JsonResponse
    {
        $result = $service->execute($program, $request->validated());

        return $this->respondWithResult(
            $result,
            $result->data instanceof Program ? new ProgramResource($result->data) : null,
        );
    }

    public function destroy(Request $request, Program $program, DeleteProgramService $service): JsonResponse
    {
        abort_unless(WorkspaceAccess::canManagePrograms($request->user(), $program->organization_id), 403);

        return $this->respondWithResult($service->execute($program));
    }
}
