<?php

namespace App\Console\Commands;

use App\Mail\MorningMail;
use App\Models\EmailDigest;
use App\Models\User;
use App\Queries\MorningEmailQuery;
use App\Support\FirstRefusals;
use App\Support\MailFailure;
use App\Support\WorkingDays;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * The morning email, once a day at `emails.morning_at`: to each person who
 * can sign in, has it on and is on a working day of their organization
 * (WorkingDays), what is late, due today or new for them
 * (MorningEmailQuery); nothing when all three are empty.
 *
 * The day is claimed first (an EmailDigest row, unique per person, kind and
 * day), so a second run the same day sends nothing. A refused address
 * (MailFailure) keeps the claim, with one warning naming the person; any
 * other failed send gives it back, so a rerun that day can send it. A "not
 * now" for one recipient (deferred) gives back only their day and the run
 * goes on; when the mail server can't be reached, or refuses the run's
 * first sends all alike (FirstRefusals), the run stops there.
 * Anything else that
 * breaks one person's email (their lists included) never stops the run for
 * the people after them. A failure is reported once per run, the rest
 * counted in one warning.
 */
class SendMorningEmails extends Command
{
    protected $signature = 'workspace:send-morning-emails {--pretend : List who would get the morning email without sending or recording anything}';

    protected $description = 'Email each person, on their working days, what is late, due today or new for them.';

    public function handle(MorningEmailQuery $query): int
    {
        $pretend = (bool) $this->option('pretend');
        $now = CarbonImmutable::now();
        $counts = ['sent' => 0, 'already' => 0, 'refused' => 0, 'deferred' => 0];
        $failed = 0;
        $stopped = false;
        /** @var FirstRefusals<array{user_id: int, error: Throwable}> $firstRefusals */
        $firstRefusals = new FirstRefusals;

        foreach (User::query()->with('preferences')->lazyById(200) as $user) {
            try {
                $outcome = $this->morning($user, $query, $now, $pretend);
            } catch (Throwable $e) {
                if (MailFailure::refused($e)) {
                    if (! $firstRefusals->hold(['user_id' => $user->id, 'error' => $e])) {
                        // The server's last word on this address: the day stays claimed.
                        $this->warnRefused($user->id, $e);
                        $counts['refused']++;

                        continue;
                    }

                    if (! $firstRefusals->serverRefuses()) {
                        continue;
                    }

                    // The first sends all refused: the server refuses the run
                    // (relay or sender), not these people. As unreachable.
                    foreach ($firstRefusals->take() as $held) {
                        $this->giveBack($held['user_id'], $now);
                    }

                    report(new RuntimeException('workspace:send-morning-emails: the mail server refused the first '.FirstRefusals::COUNT.' emails of the run, so it was stopped as if unreachable.', 0, $e));
                    $failed++;
                    $stopped = true;

                    break;
                }

                if ($failed++ === 0) {
                    report($e);
                }

                if (MailFailure::unreachable($e)) {
                    $stopped = true;

                    break;
                }

                continue;
            }

            if ($outcome !== null) {
                $counts[$outcome]++;
            }

            if ($outcome === 'sent' || $outcome === 'deferred') {
                $firstRefusals->passed();
            }
        }

        // Refusals held among the first sends that were the recipients' own after all.
        foreach ($firstRefusals->take() as $held) {
            $this->warnRefused($held['user_id'], $held['error']);
            $counts['refused']++;
        }

        if ($failed > 1) {
            Log::warning('workspace:send-morning-emails: '.($failed - 1).' more morning emails failed after the one reported.');
        }

        $this->info(($pretend ? '[pretend] ' : '')."Morning emails sent: {$counts['sent']}, already sent today: {$counts['already']}, refused: {$counts['refused']}, deferred: {$counts['deferred']}, failed: {$failed}.");

        if ($stopped) {
            $this->warn('The mail server could not be reached; stopped. Run it again today to send the rest.');
        }

        return self::SUCCESS;
    }

    /**
     * One person's morning email: `sent` (or, pretending, would be),
     * `already` sent today, `deferred` by the mail server (the day given
     * back), or null when they get none today.
     *
     * @return 'sent'|'already'|'deferred'|null
     *
     * @throws Throwable a refusal (MailFailure::refused) with the day still
     *                   claimed, or whatever else stopped it, the day given back
     */
    private function morning(User $user, MorningEmailQuery $query, CarbonImmutable $now, bool $pretend): ?string
    {
        if (! $user->emailPreferences()['morning']
            || ! WorkspaceAccess::canSignIn($user)
            || ! WorkingDays::forUser($user)->isWorkingDay($now)) {
            return null;
        }

        $lists = $query->for($user, $now);

        if ($lists['late'] === [] && $lists['today'] === [] && $lists['new'] === []) {
            return null;
        }

        $mail = new MorningMail($user, $now, $lists);

        if ($pretend) {
            $this->line("Would email {$user->email}: {$mail->envelope()->subject}");

            return 'sent';
        }

        $claimed = EmailDigest::query()->insertOrIgnore([
            'user_id' => $user->id,
            'kind' => EmailDigest::MORNING,
            'sent_on' => $now->toDateString(),
            // Now, not the run's start: what was read for this person up
            // to here is not new again tomorrow, however long the run.
            'sent_at' => CarbonImmutable::now(),
        ]) === 1;

        if (! $claimed) {
            return 'already';
        }

        try {
            Mail::to($user)->send($mail);
        } catch (Throwable $e) {
            if (MailFailure::refused($e)) {
                throw $e;
            }

            $this->giveBack($user->id, $now);

            if (MailFailure::deferred($e)) {
                // "Not now" for this person only: their day is free again, the rest go on.
                return 'deferred';
            }

            throw $e;
        }

        return 'sent';
    }

    /** Frees the person's day, unsent, so a rerun that day can send it. */
    private function giveBack(int $userId, CarbonImmutable $now): void
    {
        EmailDigest::query()
            ->where('user_id', $userId)
            ->where('kind', EmailDigest::MORNING)
            ->whereDate('sent_on', $now->toDateString())
            ->delete();
    }

    private function warnRefused(int $userId, Throwable $e): void
    {
        Log::warning("workspace:send-morning-emails: the mail server refused the morning email to user #{$userId} ({$e->getCode()}); it is not sent again today.");
    }
}
