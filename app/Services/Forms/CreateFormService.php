<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\Project;
use App\Support\Forms\FormTemplates;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * A new form in a project, as a draft, from a template (FormTemplates). Its
 * maker is the person who made it; the request has checked canCreateForm.
 */
class CreateFormService
{
    public function execute(Project $project, string $title, string $template, Authenticatable $user): ServiceResult
    {
        $title = mb_substr(trim($title), 0, Form::TITLE_MAX) ?: 'Untitled form';
        $start = FormTemplates::make($template);

        try {
            $form = new Form(['title' => $title]);
            $form->project_id = $project->id;
            $form->created_by = (int) $user->getAuthIdentifier();
            $form->code = Form::newCode();
            $form->status = Form::STATUS_DRAFT;
            $form->answering = $start['answering'];
            $form->respondents = $start['respondents'];
            $form->settings = $start['settings'];
            $form->draft = $start['draft'];
            $form->save();
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::failure("Couldn't make {$title}. Try again.", 500);
        }

        return ServiceResult::success($form, "Made {$title}.");
    }
}
