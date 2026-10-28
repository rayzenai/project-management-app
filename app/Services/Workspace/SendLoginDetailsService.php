<?php

namespace App\Services\Workspace;

use App\Mail\LoginDetails;
use App\Models\User;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails a new member the email and password they sign in with. A failed send
 * is reported and returned as a failure; the login itself already exists, so
 * whoever added the member can pass the password on by hand.
 */
class SendLoginDetailsService
{
    public function execute(User $user, string $password, User $sender): ServiceResult
    {
        try {
            Mail::to($user->email)->send(new LoginDetails(
                name: $user->name,
                email: $user->email,
                password: $password,
                sentBy: $sender->name,
            ));
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::failure("Could not send the email to {$user->email}.", 502);
        }

        return ServiceResult::success($user, 'Login details emailed.');
    }
}
