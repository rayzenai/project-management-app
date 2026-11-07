<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Support\Forms\FormSchema;
use App\Support\Forms\InvalidFormSchema;
use App\Support\ServiceResult;

/**
 * The builder's save: the whole draft, kept as FormSchema::clean returns it.
 * Saving changes only the draft; the live version changes on Publish. The
 * answer says what still stops it from being published.
 */
class SaveFormDraftService
{
    public function __construct(private FormPublishChecks $checks) {}

    public function execute(Form $form, mixed $schema): ServiceResult
    {
        try {
            $clean = FormSchema::clean($schema);
        } catch (InvalidFormSchema $e) {
            return ServiceResult::failure($e->getMessage(), 422, ['draft' => $e->getMessage()]);
        }

        $form->draft = $clean;
        $form->save();

        return ServiceResult::success([
            'draft' => $clean,
            'problems' => $this->checks->problems($form),
            'saved_at' => $form->updated_at?->toIso8601String(),
            'changed' => $this->checks->changedSincePublished($form),
        ], 'Saved.');
    }
}
