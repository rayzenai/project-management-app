<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreTeamRequest;
use App\Http\Requests\UpdateTeamRequest;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Queries\TeamIndexQuery;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * JSON sibling of the Workspace\TeamController. Reuses the same FormRequests
 * (authorization), the shared TeamIndexQuery, and the same JsonResources as the
 * web surface — the only difference is the response shape.
 */
class TeamController extends Controller
{
    use RespondsWithServiceResult;

    public function index(TeamIndexQuery $query): JsonResponse
    {
        return response()->json($query->data());
    }

    public function store(StoreTeamRequest $request): JsonResponse
    {
        $team = Team::create($request->safe()->except('member_ids'));

        $team->members()->sync($request->validated('member_ids', []));

        return response()->json([
            'message' => 'Team created.',
            'data' => new TeamResource($team->loadCount('members')->load('members:id')),
        ], 201);
    }

    public function update(UpdateTeamRequest $request, Team $team): JsonResponse
    {
        $team->update($request->safe()->except('member_ids'));

        if ($request->has('member_ids')) {
            $team->members()->sync($request->validated('member_ids', []));
        }

        return response()->json([
            'message' => 'Team updated.',
            'data' => new TeamResource($team->loadCount('members')->load('members:id')),
        ]);
    }

    public function destroy(Request $request, Team $team): JsonResponse
    {
        abort_unless(WorkspaceAccess::isSuperAdmin($request->user()), 403);

        $team->delete();

        return response()->json(['message' => 'Team deleted.']);
    }

    public function restore(Request $request, RestoreWorkspaceModel $service, Team $team): JsonResponse
    {
        abort_unless(WorkspaceAccess::isSuperAdmin($request->user()), 403);

        $result = $service->execute($team);

        return $this->respondWithResult(
            $result,
            $result->data instanceof Team ? new TeamResource($result->data->loadCount('members')->load('members:id')) : null,
        );
    }
}
