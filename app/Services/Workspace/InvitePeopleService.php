<?php

namespace App\Services\Workspace;

use App\Jobs\SendInvite;
use App\Models\Member;
use App\Models\User;
use App\Support\Background;
use App\Support\InvitePlan;
use App\Support\ServiceResult;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Invites people into one organization (the People page's "Invite people",
 * and the API's). The plan is rebuilt here (InvitePlan::build), whatever the
 * client previewed or sends. In one transaction, each `ready` person gets a
 * login with a password nobody knows: a new person also becomes one of the
 * organization's people; someone already among them is linked to it and
 * nothing else about them changes (organization, roles, teams, admin flag,
 * designation), but for their email's case: the login and the person keep
 * it lowercased, like every email an invite stores. Then each `ready` and
 * `resend` person is emailed a link to set their password, by SendInvite
 * after the commit and the response. A plan of more than
 * InvitePlan::MAX_PEOPLE rows is refused whole (422).
 *
 * @phpstan-import-type InviteInput from InvitePlan
 * @phpstan-import-type InviteRow from InvitePlan
 */
final class InvitePeopleService
{
    /**
     * What sending would do (the dialog's list), refused like the send when
     * it is more than can go at once.
     *
     * @param  list<InviteInput>  $people
     */
    public function preview(User $inviter, int $organizationId, array $people, bool $withoutLogin): ServiceResult
    {
        $preview = InvitePlan::preview($inviter, $organizationId, $people, $withoutLogin);
        $tooBig = InvitePlan::tooBig($preview['rows']);

        return $tooBig === null
            ? ServiceResult::success($preview)
            : ServiceResult::failure($tooBig, 422, ['people' => $tooBig]);
    }

    /**
     * @param  list<InviteInput>  $people
     */
    public function execute(User $inviter, int $organizationId, array $people, bool $withoutLogin): ServiceResult
    {
        try {
            /** @var array{0: list<int>, 1: list<array{name: string, email: string|null, status: string}>}|string $outcome */
            $outcome = DB::transaction(function () use ($inviter, $organizationId, $people, $withoutLogin): array|string {
                $plan = InvitePlan::build($inviter, $organizationId, $people, $withoutLogin);
                $tooBig = InvitePlan::tooBig($plan);

                if ($tooBig !== null) {
                    return $tooBig;
                }

                $invited = [];
                $skipped = [];

                foreach ($plan as $row) {
                    $userId = match ($row['status']) {
                        'ready' => $this->createLogin($row, $organizationId),
                        'resend' => $this->loginOf($row),
                        default => null,
                    };

                    if ($userId === null) {
                        $skipped[] = [
                            'name' => $row['name'],
                            'email' => $row['email'],
                            'status' => in_array($row['status'], ['ready', 'resend'], true) ? 'already_here' : $row['status'],
                        ];

                        continue;
                    }

                    $invited[] = $userId;
                }

                foreach ($invited as $userId) {
                    Background::dispatch(new SendInvite($userId, $inviter->id));
                }

                return [$invited, $skipped];
            });
        } catch (UniqueConstraintViolationException) {
            // Someone else gave one of these emails a login a moment ago.
            return ServiceResult::failure('Someone was added while you were inviting. Look at the list again and send.', 409);
        }

        if (is_string($outcome)) {
            return ServiceResult::failure($outcome, 422, ['people' => $outcome]);
        }

        [$invited, $skipped] = $outcome;
        $count = count($invited);

        return ServiceResult::success(
            ['invited' => $count, 'skipped' => $skipped],
            $count === 0 ? 'No one was invited.' : "Invited {$count} ".Str::plural('person', $count).'.',
        );
    }

    /**
     * A login with a password nobody knows (the link sets one): for a new
     * person, who joins the organization too, or for one of its people who
     * has none yet, checked again under a lock so two invites can't both
     * link them.
     *
     * @param  InviteRow  $row
     */
    private function createLogin(array $row, int $organizationId): ?int
    {
        $member = null;

        if ($row['member_id'] !== null) {
            $member = Member::query()->whereKey($row['member_id'])->lockForUpdate()->first();

            if ($member === null || $member->user_id !== null || ! $member->is_active || (int) $member->organization_id !== $organizationId) {
                return null;
            }
        }

        // Marked invited at once: if the email then fails, they are
        // `resend` next time rather than stuck as `already_here`.
        $user = User::query()->forceCreate([
            'name' => $member->name ?? $row['name'],
            'email' => $member !== null ? mb_strtolower(trim((string) $member->email)) : $row['email'],
            'password' => Hash::make(Str::random(40)),
            'invited_at' => now(),
        ]);

        if ($member !== null) {
            // Their email in the same lowercase form as the login's, so the
            // person form keeps the two (and the login's links) as one.
            $member->forceFill(['user_id' => $user->id, 'email' => $user->email])->save();

            return $user->id;
        }

        Member::create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'name' => $row['name'],
            'email' => $row['email'],
            'designation_id' => $row['designation_id'],
            'is_active' => true,
        ]);

        return $user->id;
    }

    /**
     * @param  InviteRow  $row
     */
    private function loginOf(array $row): ?int
    {
        $userId = Member::query()->whereKey($row['member_id'])->value('user_id');

        return $userId === null ? null : (int) $userId;
    }
}
