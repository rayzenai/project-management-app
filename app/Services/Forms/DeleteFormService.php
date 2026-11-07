<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Delete a form nobody has answered (test responses aside): one with
 * responses is the office's record and is closed instead. A soft delete,
 * like every other trash here.
 */
class DeleteFormService
{
    public const HAS_RESPONSES = 'It has responses, so it is kept. Close it instead.';

    public function execute(Form $form, Authenticatable $user): ServiceResult
    {
        if ($form->countedResponses() > 0) {
            return ServiceResult::failure(self::HAS_RESPONSES, 422);
        }

        $form->deleted_by = (int) $user->getAuthIdentifier();
        $form->save();
        $form->delete();

        return ServiceResult::success(null, "Deleted {$form->title}.");
    }
}
