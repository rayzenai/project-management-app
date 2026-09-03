<?php

namespace App\Http\Controllers\Workspace;

use App\Queries\DashboardQuery;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', (new DashboardQuery)->get($request));
    }
}
