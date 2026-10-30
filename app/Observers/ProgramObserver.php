<?php

namespace App\Observers;

use App\Jobs\SyncRepositoryAccess;
use App\Models\Program;
use App\Notifications\MadeProgramManager;

/**
 * A new program manager is told, unless they did it themselves, and the
 * forge access of the program's repositories follows them (the program's
 * manager maintains its projects' repositories).
 */
class ProgramObserver
{
    public function created(Program $program): void
    {
        $this->tellManager($program);
    }

    public function updated(Program $program): void
    {
        if ($program->wasChanged('manager_id')) {
            $this->tellManager($program);

            foreach ($program->projects()->orderBy('id')->get() as $project) {
                SyncRepositoryAccess::forProject($project);
            }
        }
    }

    private function tellManager(Program $program): void
    {
        $user = $program->manager?->user;

        if ($user !== null && $user->getKey() !== auth()->id()) {
            $user->notify(new MadeProgramManager($program, auth()->user()->name ?? 'Someone'));
        }
    }
}
