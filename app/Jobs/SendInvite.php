<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Workspace\PasswordLinkService;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Emails one invited person their link to set a password
 * (InvitePeopleService, through Background::dispatch: after the commit and
 * the response). It carries ids only: the token is made here, when the mail
 * goes, so it never sits in a queue. Someone deactivated, or deleted, since
 * the invite gets nothing.
 */
class SendInvite implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId, public int $inviterId) {}

    public function handle(PasswordLinkService $links): void
    {
        $user = User::query()->find($this->userId);
        $inviter = User::query()->find($this->inviterId);

        if ($user === null || $inviter === null || ! WorkspaceAccess::canSignIn($user)) {
            return;
        }

        $links->sendInvite($user, $inviter);
    }
}
