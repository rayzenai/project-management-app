<?php

namespace App\Services\Workspace;

use App\Mail\SetPasswordLink;
use App\Models\User;
use App\Support\ServiceResult;
use App\Support\WorkspaceAccess;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Throwable;

/**
 * Emailed links to set a password: `reset` (forgotten password, the `users`
 * broker, 60 minutes) and `invite` (the `invites` broker, 7 days). A link
 * works once, only for someone who may sign in (WorkspaceAccess::canSignIn),
 * and nothing it answers tells whether an email has a login here.
 */
final class PasswordLinkService
{
    public const RESET_ANSWER = 'If that email has a login here, a link to set a new password is on its way.';

    public const LINK_REFUSED = 'This link has expired or was already used.';

    /**
     * A refused link is answered no sooner than this (microseconds): an
     * unknown email, a dead token and a live one checked against the wrong
     * token then take the same time, so timing never tells which.
     */
    private const REFUSAL_MICROSECONDS = 1_000_000;

    /**
     * Always the same answer, at once. Finding the person, the token and the
     * email all happen after the response is sent (in this process, never a
     * queue, so the token is never stored in the jobs table), so neither the
     * answer nor how long it took says whether the email is known.
     */
    public function sendReset(string $email): ServiceResult
    {
        defer(fn () => $this->deliverReset($email));

        return ServiceResult::success(null, self::RESET_ANSWER);
    }

    /**
     * Emails an invite link and notes when (Task 12's job calls it). Never for
     * someone who may not sign in.
     */
    public function sendInvite(User $user, User $inviter): ServiceResult
    {
        if (! WorkspaceAccess::canSignIn($user)) {
            return ServiceResult::failure("{$user->name} is deactivated, so they can't be invited.", 422);
        }

        try {
            $token = self::broker('invite')->createToken($user);
            Mail::to($user)->send(new SetPasswordLink($user, self::url('invite', $user->email, $token), 'invite', $inviter->name));
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::failure("Couldn't send the invite to {$user->email}.", 500);
        }

        $user->forceFill(['invited_at' => now()])->save();

        return ServiceResult::success($user, "Invited {$user->name}.");
    }

    /**
     * Sets the password of whoever the link was for. A wrong, used, expired or
     * other-kind link, and a deactivated person's, all get LINK_REFUSED.
     * Ends both kinds of pending link and rotates the remember token, so no
     * "remember me" cookie outlives the old password; web sessions end
     * through AuthenticateSession. API tokens stay.
     *
     * @param  'reset'|'invite'  $kind
     */
    public function setPassword(string $kind, string $email, string $token, string $password): ServiceResult
    {
        return (new Timebox)->call(function (Timebox $timebox) use ($kind, $email, $token, $password): ServiceResult {
            $user = DB::transaction(function () use ($kind, $email, $token, $password): ?User {
                // Locked, so two uses of one link can't both pass the check
                // before either deletes the token.
                $user = User::query()->where('email', $email)->lockForUpdate()->first();
                $broker = self::broker($kind);

                if ($user === null || ! $broker->tokenExists($user, $token) || ! WorkspaceAccess::canSignIn($user)) {
                    return null;
                }

                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $this->forgetLinks($user);

                return $user;
            });

            if ($user === null) {
                return ServiceResult::failure(self::LINK_REFUSED, 422, ['token' => self::LINK_REFUSED]);
            }

            $timebox->returnEarly();

            return ServiceResult::success($user, 'Your password is set.');
        }, self::REFUSAL_MICROSECONDS);
    }

    /**
     * Ends every pending link of the person, reset and invite alike: once a
     * password is set, by a link or by an admin, no older link may set
     * another.
     */
    public function forgetLinks(User $user): void
    {
        self::broker('reset')->deleteToken($user);
        self::broker('invite')->deleteToken($user);
    }

    private function deliverReset(string $email): void
    {
        try {
            $user = User::query()->where('email', $email)->first();

            if ($user === null || ! WorkspaceAccess::canSignIn($user)) {
                return;
            }

            $token = self::broker('reset')->createToken($user);
            Mail::to($user)->send(new SetPasswordLink($user, self::url('reset', $user->email, $token), 'reset'));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  'reset'|'invite'  $kind
     */
    private static function broker(string $kind): PasswordBroker
    {
        /** @var PasswordBroker $broker the database brokers of config/auth.php */
        $broker = Password::broker($kind === 'reset' ? 'users' : 'invites');

        return $broker;
    }

    /**
     * @param  'reset'|'invite'  $kind
     */
    private static function url(string $kind, string $email, string $token): string
    {
        return route($kind === 'reset' ? 'workspace.password.reset' : 'workspace.invite.accept', ['token' => $token, 'email' => $email]);
    }
}
