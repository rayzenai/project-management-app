<?php

namespace App\Http\Controllers\Workspace;

use App\Http\Controllers\Workspace\Concerns\RedirectsWithServiceResult;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\SetPasswordRequest;
use App\Models\User;
use App\Services\Workspace\PasswordLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Forgotten password and invite links for guests: the web sibling of
 * {@see \App\Http\Controllers\Api\PasswordController}. Opening a link only
 * shows the page; nothing changes until the person sends a password.
 */
class PasswordController extends Controller
{
    use RedirectsWithServiceResult;

    public function request(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function email(ForgotPasswordRequest $request, PasswordLinkService $links): RedirectResponse
    {
        return back()->with('status', $links->sendReset($request->email())->message);
    }

    public function reset(Request $request, string $token): Response
    {
        return $this->page($request, 'reset', $token);
    }

    public function invite(Request $request, string $token): Response
    {
        return $this->page($request, 'invite', $token);
    }

    /**
     * A working link signs the person in and opens My work.
     */
    public function store(SetPasswordRequest $request, PasswordLinkService $links): RedirectResponse
    {
        $result = $links->setPassword(
            $request->kind(),
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        if ($result->failed()) {
            $request->countRefusal();

            return $this->redirectWithResult($result);
        }

        /** @var User $user */
        $user = $result->data;

        Auth::login($user);
        // As at sign-in (AuthController::store): no earlier person's
        // password hash may sign this one out.
        $request->session()->forget('password_hash_web');
        $request->session()->regenerate();

        return redirect()->route('workspace.my')
            ->with('workspace_flash', ['success' => true, 'message' => $result->message]);
    }

    /**
     * @param  'reset'|'invite'  $kind
     */
    private function page(Request $request, string $kind, string $token): Response
    {
        // Shown on the page and sent back: only ever an email address.
        $email = $request->query('email');

        return Inertia::render('Auth/SetPassword', [
            'kind' => $kind,
            'token' => $token,
            'email' => is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '',
            'status' => $request->session()->get('status'),
            'passwordRules' => SetPasswordRequest::describeRules(),
        ]);
    }
}
