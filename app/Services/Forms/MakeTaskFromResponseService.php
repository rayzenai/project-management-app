<?php

namespace App\Services\Forms;

use App\Models\FormResponse;
use App\Models\FormResponseEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\Task;
use App\Services\Workspace\AssignMemberService;
use App\Services\Workspace\CreateTaskService;
use App\Support\Forms\FormAnswerText;
use App\Support\Forms\FormSchema;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A task from a new response, when its form turns tasks on: made by the
 * system (no creator, whoever sent the response), in the chosen project,
 * assigned to the chosen person when they can still be given tasks there
 * (else unassigned, and the builder warns), with only the chosen questions'
 * answers copied into it: the project's team reads the task. A failure here
 * never undoes the response.
 */
class MakeTaskFromResponseService
{
    public function __construct(private CreateTaskService $create, private AssignMemberService $assign) {}

    public function execute(FormResponse $response): ?Task
    {
        $form = $response->form;
        $settings = $form->taskSettings();

        if ($settings === null || $response->is_test || $response->task_id !== null) {
            return null;
        }

        $project = Project::query()->find($settings['project_id']);

        if ($project === null || $project->archived_at !== null || (int) $project->organization_id !== (int) $form->project->organization_id) {
            return null;
        }

        $schema = $response->version->schema;
        $questions = FormSchema::questions($schema);
        $named = $settings['title_question'] !== null && is_string($response->answers[$settings['title_question']] ?? null)
            ? trim($response->answers[$settings['title_question']])
            : '';
        $title = mb_substr($named !== '' ? $named : "{$form->title}: {$response->reference}", 0, 255);

        $lines = ["From {$form->title}, response {$response->reference}."];

        foreach ($settings['copy_questions'] as $id) {
            if (isset($questions[$id]) && $questions[$id]['type'] !== 'file') {
                $lines[] = FormSchema::label($questions[$id]).': '.FormAnswerText::of($questions[$id], $response->answers[$id] ?? null);
            }
        }

        $lines[] = 'Open the response: '.url("/workspace/forms/{$form->id}/responses?response={$response->id}");

        try {
            $task = Task::bySystem(function () use ($project, $title, $lines, $settings): ?Task {
                $result = $this->create->execute($project, [
                    'title' => $title,
                    'description' => implode("\n\n", $lines),
                    'deadline_at' => $settings['due_days'] !== null ? CarbonImmutable::today()->addDays($settings['due_days'])->toDateString() : null,
                ]);

                if (! $result->success || ! $result->data instanceof Task) {
                    return null;
                }

                $task = $result->data;

                if (Member::query()->assignableFor($project)->whereKey($settings['assignee_id'])->exists()) {
                    $this->assign->execute($task, $settings['assignee_id'], [], null);
                }

                return $task;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if ($task === null) {
            return null;
        }

        $response->task_id = $task->id;
        $response->save();
        FormResponseEvent::query()->create(['form_response_id' => $response->id, 'kind' => 'task', 'user_id' => null, 'data' => ['task_id' => $task->id]]);

        return $task;
    }
}
