<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Models\User;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * JSON sibling of the Workspace\MemberController. Reuses the same FormRequests
 * (authorization) and mirrors its login-provisioning mutation logic, differing
 * only in the response shape.
 *
 * A member optionally carries a login: providing a password (on create or
 * later via edit) provisions a host user with the member's email, edits keep
 * the two in sync, and deleting a member removes its login too.
 */
class MemberController extends Controller
{
    use RespondsWithServiceResult;

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $withLogin = $request->filled('password');

        $member = DB::transaction(function () use ($request, $withLogin): Member {
            $userId = null;

            if ($withLogin) {
                $userId = User::create([
                    'name' => $request->validated('name'),
                    'email' => $request->validated('email'),
                    'password' => Hash::make($request->validated('password')),
                ])->getKey();
            }

            $member = Member::create([
                'user_id' => $userId,
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'title' => $request->validated('title'),
            ]);

            $member->teams()->sync($request->validated('team_ids', []));

            return $member;
        });

        return response()->json([
            'message' => $withLogin ? 'Member added — they can sign in now.' : 'Member added.',
            'data' => new MemberResource($member->load('teams:id')),
        ], 201);
    }

    public function update(UpdateMemberRequest $request, Member $member): JsonResponse
    {
        if (! $member->user_id && $request->filled('password') && ! ($request->validated('email') ?? $member->email)) {
            return response()->json([
                'message' => 'An email is required to create a login.',
                'errors' => ['email' => 'An email is required to create a login.'],
            ], 422);
        }

        DB::transaction(function () use ($request, $member): void {
            $member->update($request->safe()->except(['team_ids', 'password']));

            if ($request->has('team_ids')) {
                $member->teams()->sync($request->validated('team_ids', []));
            }

            $user = $member->user;

            if (! $user && $request->filled('password')) {
                $user = User::create([
                    'name' => $member->name,
                    'email' => $member->email,
                    'password' => Hash::make($request->validated('password')),
                ]);

                $member->user_id = $user->getKey();
                $member->save();

                return;
            }

            if ($user) {
                $user->name = $member->name;
                if ($member->email) {
                    $user->email = $member->email;
                }
                if ($request->filled('password')) {
                    $user->password = Hash::make($request->validated('password'));
                }
                $user->save();
            }
        });

        return response()->json([
            'message' => 'Member updated.',
            'data' => new MemberResource($member->fresh()->load('teams:id')),
        ]);
    }

    public function destroy(Request $request, Member $member): JsonResponse
    {
        abort_unless(WorkspaceAccess::isSuperAdmin($request->user()), 403);

        DB::transaction(function () use ($member): void {
            $user = $member->user;
            $member->delete();
            $user?->delete();
        });

        return response()->json(['message' => 'Member and their login removed.']);
    }

    public function restore(Request $request, Member $member, RestoreWorkspaceModel $service): JsonResponse
    {
        abort_unless(WorkspaceAccess::isSuperAdmin($request->user()), 403);

        $result = $service->execute($member);

        return $this->respondWithResult(
            $result,
            $result->data instanceof Member ? new MemberResource($result->data->load('teams:id')) : null,
        );
    }
}
