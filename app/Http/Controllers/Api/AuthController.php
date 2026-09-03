<?php

namespace App\Http\Controllers\Api;

use App\Models\Member;
use App\Models\User;
use App\Support\WorkspaceAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Issues and revokes Sanctum personal access tokens for the API surface, and
 * exposes the authenticated user's workspace context (member profile, super-admin
 * flag, led team ids) — the token-auth equivalent of what ShareWorkspaceData
 * injects into the Inertia web app.
 */
class AuthController extends Controller
{
    /**
     * Exchange email + password for a personal access token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
        ]);

        $userModel = User::class;

        $user = $userModel::query()->where('email', $validated['email'])->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $token = $user->createToken($validated['device_name'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Revoke the token used to make the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * The authenticated user's workspace context.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        $member = Member::query()->where('user_id', $user->getAuthIdentifier())->first();

        return [
            'id' => $user->getAuthIdentifier(),
            'name' => $user->name,
            'email' => $user->email,
            'member' => $member === null ? null : [
                'id' => $member->id,
                'name' => $member->name,
                'title' => $member->title,
            ],
            'is_super_admin' => WorkspaceAccess::isSuperAdmin($user),
            'led_team_ids' => WorkspaceAccess::ledTeamIds($user),
        ];
    }
}
