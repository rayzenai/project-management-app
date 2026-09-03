<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Notifications\TaskDeadlineDue;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class SendProjectDeadlineReminders extends Command
{
    protected $signature = 'workspace:send-deadline-reminders {--pretend : List what would be sent without notifying or logging}';

    protected $description = 'Notify assignees of upcoming and overdue task deadlines.';

    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');
        $today = today();
        $leadDays = array_values(array_map('intval', (array) config('project-management.reminders.reminder_lead_days', [2])));
        $repeat = max(1, (int) config('project-management.reminders.overdue_repeat_days', 3));

        $tasks = Task::incomplete()
            ->whereNotNull('deadline_at')
            ->with('assignments.member.user')
            ->lazy();

        $sent = 0;

        foreach ($tasks as $task) {
            $deadline = $task->deadline_at;
            if ($deadline === null) {
                continue;
            }

            $window = $this->windowFor($deadline, $today, $leadDays);
            if ($window === null) {
                continue;
            }

            $referenceDate = $this->referenceDate($window, $deadline, $today, $repeat);

            if ($pretend) {
                if ($this->alreadyClaimed($task->id, $window, $referenceDate)) {
                    continue;
                }
            } elseif (! $this->claim($task->id, $window, $referenceDate)) {
                continue;
            }

            $users = $task->assignments->pluck('member.user')->filter()->unique('id');

            foreach ($users as $user) {
                $sent++;
                if (! $pretend) {
                    $user->notify(new TaskDeadlineDue($task, $window));
                }
            }
        }

        $this->info(($pretend ? '[pretend] ' : '')."Reminders dispatched: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $leadDays
     * @return 'heads_up'|'due_today'|'overdue'|null
     */
    private function windowFor(CarbonInterface $deadline, CarbonInterface $today, array $leadDays): ?string
    {
        $deadline = $deadline->copy()->startOfDay();

        if ($deadline->lt($today)) {
            return 'overdue';
        }
        if ($deadline->equalTo($today)) {
            return 'due_today';
        }
        if (in_array((int) $today->diffInDays($deadline), $leadDays, true)) {
            return 'heads_up';
        }

        return null;
    }

    private function referenceDate(string $window, CarbonInterface $deadline, CarbonInterface $today, int $repeat): CarbonInterface
    {
        if ($window !== 'overdue') {
            return $deadline->copy()->startOfDay();
        }

        $daysOverdue = (int) $deadline->copy()->startOfDay()->diffInDays($today);
        $bucket = intdiv($daysOverdue, $repeat);

        return $deadline->copy()->startOfDay()->addDays($bucket * $repeat);
    }

    private function alreadyClaimed(int $taskId, string $window, CarbonInterface $referenceDate): bool
    {
        return DB::table('task_reminder_logs')
            ->where('task_id', $taskId)
            ->where('window', $window)
            ->where('reference_date', $referenceDate->toDateString())
            ->exists();
    }

    private function claim(int $taskId, string $window, CarbonInterface $referenceDate): bool
    {
        try {
            DB::table('task_reminder_logs')->insert([
                'task_id' => $taskId,
                'window' => $window,
                'reference_date' => $referenceDate->toDateString(),
                'sent_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }
}
