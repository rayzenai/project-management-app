<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithServiceResult;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\SetPasswordRequest;
use App\Services\Workspace\PasswordLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Forgotten password and invite links for the app: the API sibling of
 * {@see \App\Http\Controllers\Workspace\PasswordController}. Setting a
 * password answers with a message only; the app then signs in through
 * POST /api/v1/login.
 */
class PasswordController extends Controller
{
    use RespondsWithServiceResult;

    public function email(ForgotPasswordRequest $request, PasswordLinkService $links): JsonResponse
    {
        return response()->json(['message' => $links->sendReset($request->email())->message]);
    }

    public function store(SetPasswordRequest $request, PasswordLinkService $links): JsonResponse
    {
        $result = $links->setPassword(
            $request->kind(),
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        if ($result->failed()) {
            return $this->respondWithResult($result);
        }

        return response()->json(['message' => $result->message]);
    }
}
