<?php

namespace App\Http\Controllers\Workspace;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * `/workspace` opens My work, keeping the query string. The app's Home feed
 * (Api\HomeController) stays as it is.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $query = $request->getQueryString();

        return redirect()->to(route('workspace.my').($query === null ? '' : '?'.$query));
    }
}
