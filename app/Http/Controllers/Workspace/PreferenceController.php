<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\UpdatePreferenceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Persists the authenticated user's appearance preferences from the workspace
 * client (first-run onboarding + Settings → Appearance). Mirrors the Sanctum
 * API at `PATCH /api/v1/user/preferences` but over the session-authenticated
 * web guard so Inertia's `router.patch` can drive it without CSRF juggling.
 */
class PreferenceController extends Controller
{
    public function update(UpdatePreferenceRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->preferences()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated(),
        );

        return back();
    }
}
