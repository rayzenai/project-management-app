<?php

namespace App\Console\Commands;

use App\Mail\MorningMail;
use App\Models\EmailDigest;
use App\Models\User;
use App\Queries\MorningEmailQuery;
use App\Support\WorkingDays;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The morning email, once a day at `emails.morning_at`: to each person who
 * can sign in, has it on and is on a working day of their organization
 * (WorkingDays), what is late, due today or new for them
 * (MorningEmailQuery); nothing when all three are empty.
 *
 * The day is claimed first (an EmailDigest row, unique per person, kind and
 * day), so a second run the same day sends nothing; a failed send deletes
 * the claim, so a rerun that day can send it. A mail outage is reported once
 * per run, the other failures counted in one warning.
 */
class SendMorningEmails extends Command
{
    protected $signature = 'workspace:send-morning-emails {--pretend : List who would get the morning email without sending or recording anything}';

    protected $description = 'Email each person, on their working days, what is late, due today or new for them.';

    public function handle(MorningEmailQuery $query): int
    {
        $pretend = (bool) $this->option('pretend');
        $now = CarbonImmutable::now();
        $sent = 0;
        $already = 0;
        $failed = 0;

        foreach (User::query()->with('preferences')->lazyById(200) as $user) {
            if (! $user->emailPreferences()['morning']
                || ! WorkspaceAccess::canSignIn($user)
                || ! WorkingDays::forUser($user)->isWorkingDay($now)) {
                continue;
            }

            $lists = $query->for($user, $now);

            if ($lists['late'] === [] && $lists['today'] === [] && $lists['new'] === []) {
                continue;
            }

            $mail = new MorningMail($user, $now, $lists);

            if ($pretend) {
                $this->line("Would email {$user->email}: {$mail->envelope()->subject}");
                $sent++;

                continue;
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
                $already++;

                continue;
            }

            try {
                Mail::to($user)->send($mail);
                $sent++;
            } catch (Throwable $e) {
                EmailDigest::query()
                    ->where('user_id', $user->id)
                    ->where('kind', EmailDigest::MORNING)
                    ->whereDate('sent_on', $now->toDateString())
                    ->delete();

                if ($failed === 0) {
                    report($e);
                }

                $failed++;
            }
        }

        if ($failed > 1) {
            Log::warning('workspace:send-morning-emails: '.($failed - 1).' more morning emails failed to send after the one reported.');
        }

        $this->info(($pretend ? '[pretend] ' : '')."Morning emails sent: {$sent}, already sent today: {$already}, failed: {$failed}.");

        return self::SUCCESS;
    }
}
