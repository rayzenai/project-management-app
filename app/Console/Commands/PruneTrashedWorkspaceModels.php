<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\PersonalTodo;
use App\Models\ProjectAssignment;
use App\Models\ProjectContact;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\WorkspaceNote;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class PruneTrashedWorkspaceModels extends Command
{
    protected $signature = 'workspace:prune-trashed {--pretend : Count what would be pruned without deleting}';

    protected $description = 'Force-delete workspace rows trashed longer than the configured TTL.';

    /** @var list<class-string> */
    private array $models = [
        Task::class, Subtask::class, ProjectNote::class, ProjectContact::class,
        ProjectAssignment::class, WorkspaceNote::class, Team::class, Member::class,
        TaskComment::class,
    ];

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('project-management.trash_ttl_days', 30));
        $pretend = (bool) $this->option('pretend');
        $total = 0;

        foreach ($this->models as $model) {
            $total += $this->prune($model, $cutoff, $pretend);
        }

        // A deleted private to-do is kept only for its Undo.
        $total += $this->prune(PersonalTodo::class, now()->subDays(PersonalTodo::TRASH_DAYS), $pretend);

        if ($pretend) {
            $this->info("[pretend] Would prune {$total} trashed row(s).");
        } else {
            $this->info("Pruned {$total} trashed row(s).");
        }

        return self::SUCCESS;
    }

    /**
     * @param  class-string  $model
     */
    private function prune(string $model, CarbonInterface $cutoff, bool $pretend): int
    {
        $count = 0;

        foreach ($model::onlyTrashed()->where('deleted_at', '<', $cutoff)->cursor() as $row) {
            if (! $pretend) {
                $row->forceDelete();
            }

            $count++;
        }

        if ($count > 0) {
            $this->line(class_basename($model).": {$count}");
        }

        return $count;
    }
}
