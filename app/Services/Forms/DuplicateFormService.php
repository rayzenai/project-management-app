<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormFile;
use App\Models\Project;
use App\Support\Forms\FormSchema;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * A copy of a form's draft and settings, as a new draft in a project the
 * person may make forms in. Its respondents, shares, tasks and responses
 * stay behind; its pictures and files come along (the same stored files,
 * which are never deleted).
 */
class DuplicateFormService
{
    public function execute(Form $form, Project $project, Authenticatable $user): ServiceResult
    {
        try {
            $copy = DB::transaction(function () use ($form, $project, $user): Form {
                $copy = new Form(['title' => mb_substr('Copy of '.$form->title, 0, Form::TITLE_MAX)]);
                $copy->project_id = $project->id;
                $copy->created_by = (int) $user->getAuthIdentifier();
                $copy->code = Form::newCode();
                $copy->status = Form::STATUS_DRAFT;
                $copy->answering = $form->answering;
                $copy->respondents = Form::RESPONDENTS_EVERYONE;
                $copy->settings = [...$form->settings, 'tasks' => null];
                $copy->draft = $form->draft;
                $copy->save();

                $copy->draft = $this->copyFiles($form, $copy, $form->draft);
                $copy->save();

                return $copy;
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::failure("Couldn't copy {$form->title}. Try again.", 500);
        }

        return ServiceResult::success($copy, "Made a copy of {$form->title}.");
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function copyFiles(Form $from, Form $to, array $draft): array
    {
        foreach (FormSchema::steps($draft) as $s => $step) {
            foreach (FormSchema::blocks($step) as $b => $block) {
                if (! in_array($block['type'], ['image', 'download'], true) || ! is_int($block['file'] ?? null)) {
                    continue;
                }

                $file = FormFile::query()->where('form_id', $from->id)->where('purpose', FormFile::PURPOSE_CONTENT)->find($block['file']);

                if ($file === null) {
                    $draft['steps'][$s]['blocks'][$b]['file'] = null;

                    continue;
                }

                $copy = $file->replicate();
                $copy->form_id = $to->id;
                $copy->save();
                $draft['steps'][$s]['blocks'][$b]['file'] = $copy->id;
            }
        }

        return $draft;
    }
}
