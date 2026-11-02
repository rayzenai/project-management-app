<?php

namespace App\Http\Controllers\Workspace;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Emails page: the signed-in person's three email switches, reached from
 * the account menu and every email. Each switch saves through
 * PreferenceController (`PATCH /workspace/preferences`); the API reads and
 * writes the same fields at `/api/v1/user/preferences`.
 */
class EmailSettingsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Settings/Emails', [
            'emails' => $user->emailPreferences(),
            'morningAt' => (string) config('project-management.emails.morning_at', '09:00'),
        ]);
    }
}
