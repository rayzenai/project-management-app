<?php

namespace App\Jobs;

use App\Models\Member;
use App\Models\User;
use App\Services\Workspace\PasswordLinkService;
use App\Support\WorkspaceAccess;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Emails one invited person their link to set a password
 * (InvitePeopleService, through Background::dispatch: after the commit and
 * the response). It carries ids only: the token is made here, when the mail
 * goes, so it never sits in a queue. Queued only once the invite's
 * transaction commits, on any queue, so it never runs before the login
 * exists. Someone deactivated or deleted since gets nothing, and nor does
 * anyone the inviter may no longer manage (logged, no token).
 */
class SendInvite implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $userId, public int $inviterId)
    {
        $this->afterCommit = true;
    }

    public function handle(PasswordLinkService $links): void
    {
        $user = User::query()->find($this->userId);
        $inviter = User::query()->find($this->inviterId);

        if ($user === null || $inviter === null || ! WorkspaceAccess::canSignIn($user)) {
            return;
        }

        // The inviter's power may have gone since (no longer an admin, the
        // person moved, made an admin). canManageMember also holds for
        // everyone the invite itself just created, as for the people the
        // inviter may create.
        $member = Member::query()->where('user_id', $user->id)->first();

        if ($member === null || ! WorkspaceAccess::canManageMember($inviter, $member)) {
            Log::warning("Didn't send an invite: the inviter may no longer manage the person.", ['user_id' => $user->id, 'inviter_id' => $inviter->id]);

            return;
        }

        $links->sendInvite($user, $inviter);
    }
}
