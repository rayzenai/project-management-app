<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Member;
use App\Models\Project;
use App\Support\Forms\FormSchema;

/**
 * What stops a form's draft from being published, and what is only worth a
 * warning: FormSchema::problems, plus the form's own settings (tasks on with
 * nobody who can be assigned, no deadline).
 */
class FormPublishChecks
{
    /**
     * @return list<array{message: string, block: string|null, level: 'error'|'warning'}>
     */
    public function problems(Form $form): array
    {
        $published = array_values($form->versions()->get()->map(fn (FormVersion $version): array => $version->schema)->all());
        $problems = array_map(fn (array $problem): array => $problem + ['level' => 'error'], FormSchema::problems($form->draft, $published));

        $tasks = $form->taskSettings();

        if ($tasks !== null) {
            $project = Project::query()->find($tasks['project_id']);
            $assignable = $project !== null && Member::query()->assignableFor($project)->whereKey($tasks['assignee_id'])->exists();

            if (! $assignable) {
                $problems[] = ['message' => 'Tasks are on, but the person they go to can no longer be given tasks there. Choose someone else.', 'block' => null, 'level' => 'warning'];
            }
        }

        if ($form->closes_at === null) {
            $problems[] = ['message' => 'It has no close date, so it stays open until someone closes it.', 'block' => null, 'level' => 'warning'];
        }

        return $problems;
    }

    /** @param  list<array{level: string}>  $problems */
    public static function blocking(array $problems): bool
    {
        return in_array('error', array_column($problems, 'level'), true);
    }

    /** Whether the draft differs from the live version (never published counts). */
    public function changedSincePublished(Form $form): bool
    {
        $live = $form->liveSchema();

        return $live === null || json_encode($live) !== json_encode($form->draft);
    }
}
