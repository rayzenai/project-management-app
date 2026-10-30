<?php

namespace App\Observers;

use App\Jobs\SyncRepositoryAccess;
use App\Models\Program;
use App\Models\Project;
use App\Models\ProjectActivity;
use App\Services\ProjectActivityRecorder;

/**
 * Putting a project into a program, moving it or taking it out goes into
 * the project's activity, and its repositories' forge access follows the
 * program's manager.
 */
class ProjectObserver
{
    public function created(Project $project): void
    {
        if ($project->program_id === null) {
            return;
        }

        $this->record($project, null, $project->program_id, started: true);
    }

    public function updated(Project $project): void
    {
        if (! $project->wasChanged('program_id')) {
            return;
        }

        $this->record($project, $project->getOriginal('program_id'), $project->program_id);
        SyncRepositoryAccess::forProject($project);
    }

    private function record(Project $project, ?int $from, ?int $to, bool $started = false): void
    {
        $fromName = $this->name($from);
        $toName = $this->name($to);

        $description = match (true) {
            $started => "Started in the {$toName} program",
            $from === null => "Moved into the {$toName} program",
            $to === null => "Taken out of the {$fromName} program",
            default => "Moved from the {$fromName} program to the {$toName} program",
        };

        ProjectActivityRecorder::recordForProject(
            project: $project,
            subject: $project,
            action: ProjectActivity::ACTION_UPDATED,
            description: $description,
            changes: ['from' => $from, 'to' => $to],
        );
    }

    private function name(?int $id): string
    {
        return $id === null ? '' : (string) Program::query()->whereKey($id)->value('name');
    }
}
