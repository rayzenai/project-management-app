<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\Member;
use App\Models\User;
use App\Services\Workspace\RemoveFromForgeService;
use App\Services\Workspace\RestoreWorkspaceModel;
use App\Services\Workspace\SendLoginDetailsService;
use App\Support\WorkspaceAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A member carries a login: create provisions a host user with the member's
 * email and emails them the password, edits keep the two in sync (and can
 * give a login to a member from before logins were required), and deleting a
 * member removes its login too.
 */
class MemberController extends Controller
{
    public function store(StoreMemberRequest $request, SendLoginDetailsService $loginDetails): RedirectResponse
    {
        $password = $request->validated('password') ?: Str::password(14, symbols: false);

        $user = DB::transaction(function () use ($request, $password): User {
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
            ]);

            $member->teams()->sync($request->validated('team_ids', []));

            return $user;
        });

        $sent = $loginDetails->execute($user, $password, $request->user());

        return back()->with('workspace_flash', [
            'success' => $sent->success,
            'message' => $sent->success
                ? "Member added. {$sent->message}"
                : 'Member added, but the email failed. Share the password yourself.',
        ]);
    }

    public function update(UpdateMemberRequest $request, Member $member, RemoveFromForgeService $forge): RedirectResponse
    {
        if (! $member->user_id && $request->filled('password') && ! ($request->validated('email') ?? $member->email)) {
            return back()->withErrors(['email' => 'An email is required to create a login.']);
        }

        $deactivated = false;

        DB::transaction(function () use ($request, $member, &$deactivated): void {
            $member->update($request->safe()->except(['team_ids', 'password']));
            $deactivated = $member->wasChanged('is_active') && ! $member->is_active;

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

        $success = true;
        $message = 'Member updated.';
        if ($deactivated) {
            $removal = $forge->execute($member->user);
            $message = trim($message.' '.$removal->message);
            $success = $removal->meta['complete'] ?? true;
        }

        return back()->with('workspace_flash', ['success' => $success, 'message' => $message]);
    }

    public function destroy(Request $request, Member $member, RemoveFromForgeService $forge): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canManageMember($request->user(), $member), 403);

        $user = $member->user;

        DB::transaction(function () use ($member, $user): void {
            $member->delete();
            $user?->delete();
        });

        $removal = $forge->execute($user);

        return back()->with('workspace_flash', [
            'success' => $removal->meta['complete'] ?? true,
            'message' => trim('Member and their login removed. '.$removal->message),
            'undo' => [
                'label' => 'Undo',
                'url' => route('workspace.members.restore', $member),
            ],
        ]);
    }

    public function restore(Request $request, Member $member, RestoreWorkspaceModel $service): RedirectResponse
    {
        abort_unless(WorkspaceAccess::canManageMember($request->user(), $member), 403);

        $service->execute($member);

        return back()->with('workspace_flash', ['success' => true, 'message' => 'Restored.']);
    }
}
