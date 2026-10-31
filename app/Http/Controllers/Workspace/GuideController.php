<?php

namespace App\Http\Controllers\Workspace;

use App\Queries\GuideQuery;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class GuideController extends Controller
{
    public function __invoke(Request $request, GuideQuery $query): Response
    {
        return Inertia::render('Guide', $query->get($request->user()));
    }
}
