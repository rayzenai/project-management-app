<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Jobs\SyncForgeOwners;
use App\Models\Member;
use App\Models\User;
use App\Services\Structure\SetReportsToService;
use App\Services\Workspace\RemoveFromForgeService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\SendLoginDetailsService;
use App\Services\Workspace\UpdateMemberRolesService;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * JSON sibling of the Workspace\MemberController. Reuses the same FormRequests
 * (authorization) and mirrors its login-provisioning mutation logic, differing
 * only in the response shape.
 *
 * A member carries a login: create provisions a host user with the member's
 * email and emails them the password (generated when the caller sends none),
 * edits keep the two in sync, and deleting a member removes its login too.
 */
class MemberController extends Controller
{
    use RespondsWithServiceResult;

    /**
     * A save that sets someone's higher authority locks rows in another order
     * than the organogram does; on Postgres a rare clash between the two is
     * a deadlock, which Laravel retries this many times.
     */
    private const LOCK_ATTEMPTS = 3;

    public function store(StoreMemberRequest $request, SendLoginDetailsService $loginDetails, UpdateMemberRolesService $roles, SetReportsToService $reportsTo): JsonResponse
    {
        $password = $request->validated('password') ?: Str::password(14, symbols: false);

        $member = DB::transaction(function () use ($request, $password, $roles, $reportsTo): Member {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => Hash::make($password),
            ]);

            $member = Member::create([
                'user_id' => $user->getKey(),
                'organization_id' => $request->validated('organization_id'),
                'is_office_admin' => $request->validated('is_office_admin'),
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'title' => $request->validated('title'),
                ...$request->designation(),
            ]);

            if ($request->roles() !== []) {
                $this->ensureSaved($roles->execute($member, $request->roles()));
            }

            if ($request->setsReportsTo()) {
                $this->ensureSaved($reportsTo->execute($member, $request->reportsToId()));
            }

            return $member;
        });

        /** @var User $user */
        $user = $member->user;
        $sent = $loginDetails->execute($user, $password, $request->user());

        return response()->json([
            'message' => $sent->success
                ? "Member added. {$sent->message}"
                : 'Member added, but the email failed.',
            'data' => new MemberResource($member->load([...MemberResource::ROLE_RELATIONS, ...MemberResource::STRUCTURE_RELATIONS])),
        ], 201);
    }

    public function update(UpdateMemberRequest $request, Member $member, RemoveFromForgeService $forge, UpdateMemberRolesService $roles, SetReportsToService $reportsTo): JsonResponse
    {
        if (! $member->user_id && $request->filled('password') && ! ($request->validated('email') ?? $member->email)) {
            return response()->json([
                'message' => 'An email is required to create a login.',
                'errors' => ['email' => 'An email is required to create a login.'],
            ], 422);
        }

        $deactivated = false;
        $ownersChanged = [];

        DB::transaction(function () use ($request, $member, $roles, $reportsTo, &$deactivated, &$ownersChanged): void {
            $member->update([
                ...$request->safe()->except(['team_ids', 'managed_program_ids', 'managed_project_ids', 'password', 'designation_id', 'reports_to_id']),
                ...$request->designation(),
            ]);
            $deactivated = $member->wasChanged('is_active') && ! $member->is_active;
            $ownersChanged = SyncForgeOwners::organizationsChangedBy($member);

            if ($request->roles() !== []) {
                $this->ensureSaved($roles->execute($member, $request->roles()));
            }

            if ($request->setsReportsTo()) {
                $this->ensureSaved($reportsTo->execute($member, $request->reportsToId()));
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
        }, self::LOCK_ATTEMPTS);

        SyncForgeOwners::dispatchFor($ownersChanged);

        $message = $request->roles() === [] ? 'Member updated.' : 'Member updated. Roles saved.';

        return response()->json([
            'message' => $deactivated
                ? trim($message.' '.$forge->execute($member->user)->message)
                : $message,
            'data' => new MemberResource($member->fresh()->load([...MemberResource::ROLE_RELATIONS, ...MemberResource::STRUCTURE_RELATIONS])),
        ]);
    }

    public function destroy(Request $request, Member $member, RemoveFromForgeService $forge): JsonResponse
    {
        abort_unless(WorkspaceAccess::canManageMember($request->user(), $member), 403);

        $user = $member->user;

        DB::transaction(function () use ($member, $user): void {
            $member->delete();
            $user?->delete();
        });

        return response()->json(['message' => trim('Member and their login removed. '.$forge->execute($user)->message)]);
    }

    public function restore(Request $request, Member $member, RestoreWorkspaceModel $service): JsonResponse
    {
        abort_unless(WorkspaceAccess::canManageMember($request->user(), $member), 403);

        $result = $service->execute($member);

        return $this->respondWithResult(
            $result,
            $result->data instanceof Member ? new MemberResource($result->data->load([...MemberResource::ROLE_RELATIONS, ...MemberResource::STRUCTURE_RELATIONS])) : null,
        );
    }

    /**
     * A refused role change undoes the whole save: thrown inside the
     * transaction, it rolls back and answers 422 on the field.
     */
    private function ensureSaved(ServiceResult $result): void
    {
        if ($result->success) {
            return;
        }

        if (is_array($result->data) && $result->data !== []) {
            throw ValidationException::withMessages($result->data);
        }

        throw new RuntimeException((string) $result->message);
    }
}
