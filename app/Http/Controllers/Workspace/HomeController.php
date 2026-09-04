<?php

namespace App\Http\Controllers\Workspace;

use App\Queries\HomeQuery;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, HomeQuery $query): Response
    {
        return Inertia::render('Home', $query->get($request));
    }
}
