<?php

namespace App\Http\Controllers\Workspace;

use App\Queries\MyWorkspaceQuery;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class MyWorkspaceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('MyWorkspace', (new MyWorkspaceQuery)->get($request));
    }
}
