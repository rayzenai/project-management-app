<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\SwitchOrganizationRequest;
use App\Support\WorkspaceOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Switches a super-admin's workspace to an organization, or to all of them,
 * and opens the page they were on again (WorkspaceOrganization).
 */
class OrganizationSwitchController extends Controller
{
    public function __invoke(SwitchOrganizationRequest $request): RedirectResponse
    {
        WorkspaceOrganization::choose($request->user(), $request->organizationId());

        return redirect()->to(self::pageWithoutOrganization(url()->previous()));
    }

    /**
     * The page the switch was used on, opened on the new choice: its path
     * (a workspace page, never another host) without the `organization` it
     * named, which would switch back, nor `under`, someone of that
     * organization.
     */
    private static function pageWithoutOrganization(string $previous): string
    {
        $path = (string) parse_url($previous, PHP_URL_PATH);

        if (! str_starts_with($path, '/workspace')) {
            return route('workspace.home');
        }

        parse_str((string) parse_url($previous, PHP_URL_QUERY), $query);
        unset($query['organization'], $query['under']);

        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }
}
