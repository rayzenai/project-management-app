<?php

namespace App\Http\Controllers\Workspace;

use App\Queries\TeamIndexQuery;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The People page: the directory of people the caller may see, and the
 * project teams they sit on. Teams are made with their projects.
 */
class TeamController extends Controller
{
    public function index(Request $request, TeamIndexQuery $query): Response
    {
        return Inertia::render('Team/Index', $query->data($request));
    }
}
