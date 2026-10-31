<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\WorkspaceAccess;
use App\Support\WorkspaceOrganization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picking an organization on a page (`?organization=` on the organogram,
 * maintenance, attendance, the work reports and People, or People's `all`)
 * is the workspace's switch too, so the sidebar follows what a super-admin
 * is looking at (WorkspaceOrganization). Only a page visit by a super-admin,
 * only an organization that exists; anything else changes nothing.
 */
class RemembersOrganizationChoice
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $picked = $request->query('organization');

        if ($request->isMethod('GET') && is_string($picked) && $user instanceof User && WorkspaceAccess::isSuperAdmin($user)) {
            if ($picked === 'all') {
                WorkspaceOrganization::choose($user, null);
            } elseif (ctype_digit($picked) && Organization::query()->whereKey((int) $picked)->exists()) {
                WorkspaceOrganization::choose($user, (int) $picked);
            }
        }

        return $next($request);
    }
}
