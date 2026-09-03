<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Session login/logout for the workspace web (Inertia) surface — the web sibling
 * of {@see \App\Http\Controllers\Api\AuthController}, which
 * handles token auth for the API. Thin: validation + rate-limited authentication
 * live in {@see LoginRequest}.
 */
class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended('/workspace');
    }

    /**
     * Log out and bounce to the login page. Uses Inertia::location for a full
     * page visit so the (now guest) browser leaves the authenticated SPA cleanly
     * with a fresh CSRF token, rather than an in-SPA visit that cannot render.
     */
    public function destroy(Request $request): SymfonyResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Inertia::location(route('workspace.login'));
    }
}
