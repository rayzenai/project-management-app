<?php

namespace App\Support;

use App\Models\Designation;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who an invite would reach (the People page's "Invite people"): one row per
 * person with what sending would do. Built afresh on the server for the
 * preview and again for the send, so a status, id or designation a client
 * sends back is never trusted.
 *
 * It never creates, links or tells apart anyone outside the organization:
 * another organization's person, with a login or without, is `already_here`
 * exactly like one of its own who has signed in, with no id, name or
 * designation of theirs in the row. Only people of the organization carry a
 * `member_id`.
 *
 * Emails are lowercased: the rows show, and an invite stores, the lowercase
 * form, and every comparison (logins, people, repeats in the list,
 * super-admins' emails) ignores case, so "Deepak@x" and "deepak@x" are one
 * person. At most MAX_PEOPLE rows are sent at once, the pasted people and
 * the ones the "never signed in" box adds together (tooBig).
 *
 * Statuses: `ready` (a new person, or one of the organization's people with
 * no login yet), `resend` (invited, never signed in: a fresh link),
 * `invited_recently` (the same, invited less than RESEND_AFTER_MINUTES ago:
 * not mailed again, so repeated sends can't flood a mailbox), `needs_email`, `needs_name`, `already_here`, `deactivated` (one of the
 * organization's people who is deactivated), `not_allowed` (one of its
 * people the inviter may not manage, such as another organizational admin,
 * or a super-admin's email for anyone but a super-admin).
 *
 * @phpstan-type InviteStatus 'ready'|'resend'|'invited_recently'|'needs_email'|'needs_name'|'already_here'|'deactivated'|'not_allowed'
 * @phpstan-type InviteInput array{name: string|null, email: string|null, designation_id?: int|null}
 * @phpstan-type InviteRow array{name: string, email: string|null, designation_id: int|null, status: InviteStatus, member_id: int|null}
 */
final class InvitePlan
{
    public const MAX_PEOPLE = 200;

    /** Someone invited this recently gets no fresh link yet. */
    public const RESEND_AFTER_MINUTES = 10;

    /**
     * One person per non-empty line: "Name, email", "Name <email>",
     * "Name email" (a tab too, as a spreadsheet pastes). The email is the
     * last word an email check accepts; the rest is the name. A line that
     * is only an email has the name ''; one with no email keeps the whole
     * line as the name. At most MAX_PEOPLE lines are read.
     *
     * @return list<array{name: string, email: string|null}>
     */
    public static function parse(string $text): array
    {
        $people = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (count($people) === self::MAX_PEOPLE) {
                break;
            }

            $people[] = self::parseLine($line);
        }

        return $people;
    }

    /**
     * The plan for these people in the organization; with $withoutLogin,
     * also every active person of the organization with an email and no
     * login who is not listed already, when they would be `ready`.
     *
     * @param  list<InviteInput>  $people
     * @return list<InviteRow>
     */
    public static function build(User $inviter, int $organizationId, array $people, bool $withoutLogin): array
    {
        [$listed, $unlisted] = self::plan($inviter, $organizationId, $people);

        return $withoutLogin ? [...$listed, ...$unlisted] : $listed;
    }

    /**
     * The preview: the plan, and how many people the "never signed in"
     * checkbox would add to it.
     *
     * @param  list<InviteInput>  $people
     * @return array{rows: list<InviteRow>, without_login_count: int}
     */
    public static function preview(User $inviter, int $organizationId, array $people, bool $withoutLogin): array
    {
        [$listed, $unlisted] = self::plan($inviter, $organizationId, $people);

        return [
            'rows' => $withoutLogin ? [...$listed, ...$unlisted] : $listed,
            'without_login_count' => count($unlisted),
        ];
    }

    /**
     * The refusal when a plan is more than can be sent at once, else null.
     *
     * @param  list<InviteRow>  $rows
     */
    public static function tooBig(array $rows): ?string
    {
        if (count($rows) <= self::MAX_PEOPLE) {
            return null;
        }

        $invited = count(array_filter($rows, fn (array $row): bool => in_array($row['status'], ['ready', 'resend'], true)));

        return "That's ".count($rows)." people, {$invited} to invite. Invite at most ".self::MAX_PEOPLE.' at a time.';
    }

    public static function isEmail(string $value): bool
    {
        return strlen($value) <= 255 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * @return array{name: string, email: string|null}
     */
    private static function parseLine(string $line): array
    {
        $words = preg_split('/[\s,;<>]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $email = null;

        foreach (array_reverse($words) as $word) {
            if (self::isEmail($word)) {
                $email = $word;

                break;
            }
        }

        $name = $line;

        if ($email !== null) {
            $at = (int) mb_strrpos($line, $email);
            $name = mb_substr($line, 0, $at).mb_substr($line, $at + mb_strlen($email));
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " ,;<>\"'");

        return ['name' => mb_substr($name, 0, 255), 'email' => $email];
    }

    /**
     * The listed people's rows, and the unlisted people without a login who
     * would be ready.
     *
     * @param  list<InviteInput>  $people
     * @return array{0: list<InviteRow>, 1: list<InviteRow>}
     */
    private static function plan(User $inviter, int $organizationId, array $people): array
    {
        $unlisted = Member::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->whereNull('user_id')
            ->whereNotNull('email')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $inputs = array_map(fn (array $person): array => [
            'name' => trim((string) ($person['name'] ?? '')),
            'email' => mb_strtolower(trim((string) ($person['email'] ?? ''))),
            'designation_id' => $person['designation_id'] ?? null,
        ], $people);

        $emails = [];
        foreach ([...array_column($inputs, 'email'), ...$unlisted->pluck('email')->all()] as $email) {
            if (is_string($email) && self::isEmail($email)) {
                $emails[mb_strtolower($email)] = true;
            }
        }
        $emails = array_keys($emails);

        $users = self::usersByEmail($emails);
        $membersOfUsers = $users === [] ? collect() : Member::query()->whereIn('user_id', array_map(fn (User $user): int => $user->id, $users))->get()->keyBy('user_id');
        $members = self::membersByEmail($emails);
        $designations = Designation::query()->where('organization_id', $organizationId)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $isSuperAdmin = WorkspaceAccess::isSuperAdmin($inviter);
        $ours = fn (Member $member): bool => $member->organization_id !== null && (int) $member->organization_id === $organizationId;

        /** @var array<string, true> $seen lowercased emails already planned */
        $seen = [];

        // Only a designation of the organization is kept.
        $row = function (string $name, string $email, ?int $designationId) use ($inviter, $users, $membersOfUsers, $members, $designations, $isSuperAdmin, $ours, &$seen): array {
            $designationId = in_array($designationId, $designations, true) ? $designationId : null;

            if (! self::isEmail($email)) {
                return self::row($name, null, $designationId, 'needs_email');
            }

            if ($name === '') {
                return self::row($name, $email, $designationId, 'needs_name');
            }

            $key = mb_strtolower($email);

            if (isset($seen[$key])) {
                return self::row($name, $email, $designationId, 'already_here');
            }

            $seen[$key] = true;
            $user = $users[$key] ?? null;

            if ($user !== null) {
                $member = $membersOfUsers->get($user->id);

                if ($member === null || ! $ours($member)) {
                    return self::row($name, $email, $designationId, 'already_here');
                }

                if (! $member->is_active) {
                    return self::row($name, $email, $designationId, 'deactivated', $member);
                }

                $member->setRelation('user', $user);

                if ($user->invited_at === null || $user->last_seen_at !== null || ! WorkspaceAccess::canManageMember($inviter, $member)) {
                    return self::row($name, $email, $designationId, 'already_here');
                }

                return $user->invited_at->greaterThan(now()->subMinutes(self::RESEND_AFTER_MINUTES))
                    ? self::row($name, $email, $designationId, 'invited_recently', $member)
                    : self::row($name, $email, $designationId, 'resend', $member);
            }

            $withEmail = $members[$key] ?? null;
            $own = $withEmail?->filter($ours);

            if ($own !== null && $own->isNotEmpty()) {
                // One of them has a login under another email.
                if ($own->contains(fn (Member $member): bool => $member->user_id !== null)) {
                    return self::row($name, $email, $designationId, 'already_here');
                }

                // Active ones come first.
                $member = $own->firstOrFail();

                if (! $member->is_active) {
                    return self::row($name, $email, $designationId, 'deactivated', $member);
                }

                return WorkspaceAccess::canManageMember($inviter, $member) ? self::row($name, $email, $designationId, 'ready', $member) : self::row($name, $email, $designationId, 'not_allowed', $member);
            }

            if ($withEmail !== null) {
                return self::row($name, $email, $designationId, 'already_here');
            }

            // The Gate matches on email: a login with this one would be a super-admin.
            if (! $isSuperAdmin && WorkspaceAccess::isSuperAdminEmail($email)) {
                return self::row($name, $email, $designationId, 'not_allowed');
            }

            return self::row($name, $email, $designationId, 'ready');
        };

        $listed = array_map(fn (array $input): array => $row($input['name'], $input['email'], $input['designation_id']), $inputs);

        // Planned after the listed people, so anyone listed already is
        // `already_here` here and left out.
        $extra = [];
        foreach ($unlisted as $member) {
            $planned = $row($member->name, mb_strtolower(trim((string) $member->email)), null);

            if ($planned['status'] === 'ready') {
                $extra[] = $planned;
            }
        }

        return [$listed, $extra];
    }

    /**
     * Only the organization's own people ($member) show their id and
     * designation.
     *
     * @param  InviteStatus  $status
     * @return InviteRow
     */
    private static function row(string $name, ?string $email, ?int $designationId, string $status, ?Member $member = null): array
    {
        return [
            'name' => $name,
            'email' => $email,
            'designation_id' => $member !== null ? $member->designation_id : $designationId,
            'status' => $status,
            'member_id' => $member?->id,
        ];
    }

    /**
     * @param  list<string>  $emails  lowercased
     * @return array<string, User>
     */
    private static function usersByEmail(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $users = [];
        foreach (User::query()->whereIn(DB::raw('lower(email)'), $emails)->orderBy('id')->get() as $user) {
            $users[mb_strtolower($user->email)] ??= $user;
        }

        return $users;
    }

    /**
     * Everyone with these emails, in any organization, active people and
     * the oldest first.
     *
     * @param  list<string>  $emails  lowercased
     * @return array<string, Collection<int, Member>>
     */
    private static function membersByEmail(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return Member::query()
            ->whereIn(DB::raw('lower(email)'), $emails)
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Member $member): string => mb_strtolower((string) $member->email))
            ->all();
    }
}
