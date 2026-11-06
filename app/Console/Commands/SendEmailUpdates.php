<?php

namespace App\Console\Commands;

use App\Mail\UpdatesMail;
use App\Models\User;
use App\Notifications\AddedToProjectTeam;
use App\Notifications\DocumentShared;
use App\Notifications\MadeProgramManager;
use App\Notifications\MadeProjectManager;
use App\Notifications\MentionedInComment;
use App\Notifications\TaskAssigned;
use App\Support\FirstRefusals;
use App\Support\MailFailure;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/**
 * Right-away email: the in-app notifications of KINDS a person has not read,
 * sent as one UpdatesMail once nothing new has come for them for
 * `updates_quiet_minutes`, or once the oldest has waited
 * `updates_max_wait_minutes` (config project-management.emails).
 *
 * Every row is claimed (`email_handled_at`) before the email goes, one
 * conditional update per row, so an overlapping run never sends it again.
 * Someone who turned right-away email off, or can no longer sign in, has
 * their rows marked handled with no email, so turning it on later never
 * sends old news. A failed send is sorted by MailFailure: when the mail
 * server can't be reached the claim is given back and the run stops there
 * (the next minute tries again, and nobody waits on one timeout per
 * person); a "not now" for one recipient (deferred) gives back only their
 * rows and the run goes on; a refused address stays handled with one
 * warning naming the person, never tried again, unless the run's first
 * sends were all refused (FirstRefusals: the server refusing everyone,
 * handled as unreachable); any other failure stays handled too. A failure is reported once per run, the rest counted in one
 * warning.
 */
class SendEmailUpdates extends Command
{
    /** The notifications that are emailed right away. */
    public const array KINDS = [
        TaskAssigned::class,
        MentionedInComment::class,
        AddedToProjectTeam::class,
        MadeProjectManager::class,
        MadeProgramManager::class,
        DocumentShared::class,
    ];

    protected $signature = 'workspace:send-email-updates {--pretend : List what would be sent without sending or marking anything}';

    protected $description = 'Email people, grouped, what was given to them in the last few minutes.';

    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $now = CarbonImmutable::now();
        $quietSince = $now->subMinutes((int) config('project-management.emails.updates_quiet_minutes', 2));
        $waitedSince = $now->subMinutes((int) config('project-management.emails.updates_max_wait_minutes', 5));

        $waiting = DatabaseNotification::query()
            ->where('notifiable_type', (new User)->getMorphClass())
            ->whereIn('type', self::KINDS)
            ->whereNull('read_at')
            ->whereNull('email_handled_at')
            ->where('created_at', '>=', $now->subDay())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'notifiable_id', 'data', 'created_at'])
            ->groupBy('notifiable_id');

        // More may be on its way while the newest is fresh, up to the wait.
        $ready = $waiting->filter(fn (Collection $rows): bool => $rows->last()?->created_at?->lte($quietSince)
            || $rows->first()?->created_at?->lte($waitedSince));

        $users = User::query()->with('preferences')->whereKey($ready->keys())->get()->keyBy('id');
        $sent = 0;
        $skipped = 0;
        $refused = 0;
        $deferred = 0;
        $failed = 0;
        $stopped = false;
        /** @var FirstRefusals<array{user_id: int, claimed: list<string>, error: Throwable}> $firstRefusals */
        $firstRefusals = new FirstRefusals;

        foreach ($ready as $userId => $rows) {
            $user = $users->get($userId);
            $wanted = $user !== null && $user->emailPreferences()['now'] && WorkspaceAccess::canSignIn($user);

            if ($pretend) {
                $this->line(($wanted ? 'Would email ' : 'Would skip ').($user->email ?? "user #{$userId}").': '.$rows->count().' updates');
                $wanted ? $sent++ : $skipped++;

                continue;
            }

            $claimed = $this->claim($rows->pluck('id'), $now);

            if ($claimed === []) {
                continue;
            }

            if ($user === null || ! $wanted) {
                $skipped++;

                continue;
            }

            $items = array_values($rows->whereIn('id', $claimed)
                ->map(fn (DatabaseNotification $row): array => self::item($row->data))
                ->all());

            try {
                Mail::to($user)->send(new UpdatesMail($user, $items));
                $sent++;
                $firstRefusals->passed();
            } catch (Throwable $e) {
                if (MailFailure::refused($e)) {
                    if (! $firstRefusals->hold(['user_id' => $user->id, 'claimed' => $claimed, 'error' => $e])) {
                        // The server's last word on this address: the rows stay handled.
                        $this->warnRefused($user->id, $e);
                        $refused++;

                        continue;
                    }

                    if (! $firstRefusals->serverRefuses()) {
                        continue;
                    }

                    // The first sends all refused: the server refuses the run
                    // (relay or sender), not these people. As unreachable.
                    foreach ($firstRefusals->take() as $held) {
                        $this->release($held['claimed']);
                    }

                    report(new RuntimeException('workspace:send-email-updates: the mail server refused the first '.FirstRefusals::COUNT.' emails of the run, so it was stopped as if unreachable.', 0, $e));
                    $failed++;
                    $stopped = true;

                    break;
                }

                if (MailFailure::deferred($e)) {
                    // "Not now" for this person only: theirs wait, the rest go on.
                    $this->release($claimed);
                    $deferred++;
                    $firstRefusals->passed();

                    continue;
                }

                if ($failed++ === 0) {
                    report($e);
                }

                if (MailFailure::unreachable($e)) {
                    $this->release($claimed);
                    $stopped = true;

                    break;
                }
            }
        }

        // Refusals held among the first sends that were the recipients' own after all.
        foreach ($firstRefusals->take() as $held) {
            $this->warnRefused($held['user_id'], $held['error']);
            $refused++;
        }

        if ($failed > 1) {
            Log::warning('workspace:send-email-updates: '.($failed - 1).' more update emails failed to send after the one reported.');
        }

        if ($pretend || $sent + $skipped + $refused + $deferred + $failed > 0) {
            $this->info(($pretend ? '[pretend] ' : '')."Update emails sent: {$sent}, skipped: {$skipped}, refused: {$refused}, deferred: {$deferred}, failed: {$failed}.");
        }

        if ($stopped) {
            $this->warn('The mail server could not be reached; the rest wait for the next run.');
        }

        return self::SUCCESS;
    }

    /**
     * How the email names one notification: what happened and to what, then
     * the notification's own sentence and a link to where it happened.
     *
     * @param  array<array-key, mixed>  $data  the notification's payload
     * @return array{title: string, line: string, url: string}
     */
    public static function item(array $data): array
    {
        $title = match ($data['kind'] ?? null) {
            'task_assigned' => 'Task assigned: '.self::text($data, 'task', 'title'),
            'mentioned_in_comment' => 'You were mentioned: '.self::text($data, 'task', 'title'),
            'team_added' => 'Added to a project: '.self::text($data, 'project', 'title'),
            'made_manager' => 'Project manager: '.self::text($data, 'project', 'title'),
            'made_program_manager' => 'Program manager: '.self::text($data, 'program', 'name'),
            'document_shared' => 'Shared with you: '.self::text($data, 'document', 'title'),
            default => self::text($data, 'title'),
        };

        return [
            'title' => $title,
            'line' => self::text($data, 'body'),
            'url' => url(self::text($data, 'url') ?: '/workspace/my'),
        ];
    }

    /**
     * Marks each row handled, one conditional update at a time, while it is
     * still unhandled and unread: the ids this run now owns. A row another
     * run claimed first, or that was read meanwhile, is left to it.
     *
     * @param  Collection<int, mixed>  $ids
     * @return list<string>
     */
    private function claim(Collection $ids, CarbonImmutable $at): array
    {
        return array_values($ids->map(fn (mixed $id): string => (string) $id)
            ->filter(fn (string $id): bool => DB::table('notifications')
                ->where('id', $id)
                ->whereNull('email_handled_at')
                ->whereNull('read_at')
                ->update(['email_handled_at' => $at]) === 1)
            ->all());
    }

    private function warnRefused(int $userId, Throwable $e): void
    {
        Log::warning("workspace:send-email-updates: the mail server refused the update email to user #{$userId} ({$e->getCode()}); it is not tried again.");
    }

    /**
     * Gives claimed rows back, unsent, for the next run.
     *
     * @param  list<string>  $ids
     */
    private function release(array $ids): void
    {
        DB::table('notifications')->whereIn('id', $ids)->update(['email_handled_at' => null]);
    }

    /** @param  array<array-key, mixed>  $data */
    private static function text(array $data, string ...$path): string
    {
        $value = $data;

        foreach ($path as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_string($value) ? $value : '';
    }
}
