<?php

namespace App\Queries;

use App\Models\EmailDigest;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Carbon\CarbonImmutable;

/**
 * What a person's morning email lists, read from My work (MyWorkQuery) so
 * the two never disagree.
 *
 * `late` and `today` are My work's Today tasks, split by whether the task
 * is past its own deadline (to-dos are left out). `new` is the tasks given
 * to them (TaskAssigned) since their last morning email, or in the last day
 * before their first, that are still open and on their list (not snoozed,
 * not only something they asked of others), and not already late or today;
 * each once, in the order they came, with who gave it last.
 *
 * @phpstan-import-type MyWorkRow from MyWorkQuery
 *
 * @phpstan-type MorningRow array{task_id: int, title: string, url: string, project: string, due: string|null, days_late: int, from: string|null}
 * @phpstan-type MorningLists array{late: list<MorningRow>, today: list<MorningRow>, new: list<MorningRow>}
 */
final class MorningEmailQuery
{
    /**
     * The My work groups a new task may sit in: on the list, not snoozed,
     * not only asked of others, and not already late or today.
     */
    private const NEW_GROUPS = ['tomorrow', 'coming_up', 'later', 'no_date'];

    /** The name the observers give when nobody signed in did it: not a person. */
    private const UNKNOWN_GIVER = 'Someone';

    public function __construct(private readonly MyWorkQuery $myWork) {}

    /**
     * `$today` is the moment of the run: its date is the day, and the day
     * before it is how far back new work reaches before the first email.
     *
     * @return MorningLists
     */
    public function for(User $user, CarbonImmutable $today): array
    {
        /** @var array<string, list<MyWorkRow>> $tasks each group's task rows (no to-dos) by its key */
        $tasks = [];

        foreach ($this->myWork->get($user, null, false, $today)['groups'] as $group) {
            $tasks[$group['key']] = array_values(array_filter($group['rows'], fn (array $row): bool => $row['kind'] === 'task'));
        }

        $onList = [];

        foreach (self::NEW_GROUPS as $key) {
            foreach ($tasks[$key] ?? [] as $row) {
                $onList[$row['task']['slug']] = $row;
            }
        }

        $new = [];

        foreach ($this->givenSince($user, $today) as $slug => $from) {
            if (isset($onList[$slug])) {
                $new[] = $this->row($onList[$slug], $from);
            }
        }

        $late = [];
        $dueToday = [];

        foreach ($tasks['today'] ?? [] as $row) {
            if ($row['days_late'] > 0) {
                $late[] = $this->row($row, null);
            } else {
                $dueToday[] = $this->row($row, null);
            }
        }

        return ['late' => $late, 'today' => $dueToday, 'new' => $new];
    }

    /**
     * The tasks named by the person's TaskAssigned notifications since their
     * last morning email (else the last day): task slug => who gave it
     * (null when not known), in the order they first came, with the latest
     * giver.
     *
     * @return array<string, string|null>
     */
    private function givenSince(User $user, CarbonImmutable $today): array
    {
        $since = EmailDigest::query()
            ->where('user_id', $user->id)
            ->where('kind', EmailDigest::MORNING)
            ->orderByDesc('sent_at')
            ->value('sent_at') ?? $today->subDay();

        $given = [];

        $notifications = $user->notifications()
            ->where('type', TaskAssigned::class)
            ->where('created_at', '>', $since)
            ->reorder()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'data', 'created_at']);

        foreach ($notifications as $notification) {
            $slug = $notification->data['task']['slug'] ?? null;
            $from = $notification->data['actor']['name'] ?? null;

            if (is_string($slug)) {
                $given[$slug] = is_string($from) && ! in_array($from, ['', self::UNKNOWN_GIVER], true) ? $from : null;
            }
        }

        return $given;
    }

    /**
     * @param  MyWorkRow  $row
     * @return MorningRow
     */
    private function row(array $row, ?string $from): array
    {
        $task = $row['task'];

        return [
            'task_id' => $task['id'],
            'title' => $task['title'],
            'url' => url("/workspace/projects/{$task['project']['slug']}/tasks/{$task['slug']}"),
            'project' => $task['project']['label'],
            'due' => $row['due'],
            'days_late' => $row['days_late'],
            'from' => $from,
        ];
    }
}
