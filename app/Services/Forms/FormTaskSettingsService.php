<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Support\ServiceResult;

/**
 * Tasks from responses: off, or on into a project for one person. The
 * request has checked canSendFormTasksTo and that the person can be
 * assigned there. Turning it on makes no tasks for responses already in.
 */
class FormTaskSettingsService
{
    /**
     * @param  array{project_id: int, assignee_id: int, due_days: int|null, title_question: string|null, copy_questions: list<string>}|null  $tasks
     */
    public function execute(Form $form, ?array $tasks): ServiceResult
    {
        $form->settings = [...$form->settings, 'tasks' => $tasks];
        $form->save();

        return ServiceResult::success($form, $tasks === null
            ? "New responses to {$form->title} no longer make tasks."
            : "Each new response to {$form->title} now makes a task.");
    }
}
