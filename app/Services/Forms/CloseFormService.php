<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Support\ServiceResult;

/**
 * Close a form (no more responses, nothing editable) and reopen it. A
 * reopened form whose close date has passed gets none, so it stays open
 * until it is closed again.
 */
class CloseFormService
{
    public function close(Form $form): ServiceResult
    {
        $form->status = Form::STATUS_CLOSED;
        $form->save();

        return ServiceResult::success($form, "Closed {$form->title}. No one can send or change a response now.");
    }

    public function reopen(Form $form): ServiceResult
    {
        if ($form->published_version_id === null) {
            return ServiceResult::failure('Publish it first.', 422);
        }

        $form->status = Form::STATUS_OPEN;

        if ($form->closes_at !== null && $form->closes_at->isPast()) {
            $form->closes_at = null;
        }

        $form->save();

        return ServiceResult::success($form, "Reopened {$form->title}.");
    }
}
