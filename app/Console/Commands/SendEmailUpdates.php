<?php

namespace App\Console\Commands;

use App\Mail\UpdatesMail;
use App\Models\User;
use App\Notifications\AddedToProjectTeam;
use App\Notifications\MadeProgramManager;
use App\Notifications\MadeProjectManager;
use App\Notifications\MentionedInComment;
use App\Notifications\TaskAssigned;
use App\Support\MailFailure;
use App\Support\WorkspaceAccess;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
 * sends old news. When the mail server can't be reached (MailFailure) the
 * claim is given back and the run stops there: the next minute tries again,
 * and nobody waits on one timeout per person. A refused address stays
 * handled with one warning naming the person, never tried again; any other
 * failure stays handled too. A failure is reported once per run, the rest
 * counted in one warning.
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
        $failed = 0;
        $stopped = false;

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
            } catch (Throwable $e) {
                if (MailFailure::refused($e)) {
                    // The server's last word on this address: the rows stay handled.
                    Log::warning("workspace:send-email-updates: the mail server refused the update email to user #{$user->id} ({$e->getCode()}); it is not tried again.");
                    $refused++;

                    continue;
                }

                if ($failed++ === 0) {
                    report($e);
                }

                if (MailFailure::unreachable($e)) {
                    DB::table('notifications')->whereIn('id', $claimed)->update(['email_handled_at' => null]);
                    $stopped = true;

                    break;
                }
            }
        }

        if ($failed > 1) {
            Log::warning('workspace:send-email-updates: '.($failed - 1).' more update emails failed to send after the one reported.');
        }

        if ($pretend || $sent + $skipped + $refused + $failed > 0) {
            $this->info(($pretend ? '[pretend] ' : '')."Update emails sent: {$sent}, skipped: {$skipped}, refused: {$refused}, failed: {$failed}.");
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
