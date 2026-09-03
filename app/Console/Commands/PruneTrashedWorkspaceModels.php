<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\ProjectAssignment;
use App\Models\ProjectContact;
use App\Models\ProjectNote;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\Team;
use App\Models\WorkspaceNote;
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

            $total += $count;
        }

        if ($pretend) {
            $this->info("[pretend] Would prune {$total} trashed row(s).");
        } else {
            $this->info("Pruned {$total} trashed row(s).");
        }

        return self::SUCCESS;
    }
}
