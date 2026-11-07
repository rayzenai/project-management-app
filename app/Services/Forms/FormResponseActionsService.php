<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormResponse;
use App\Models\FormResponseEvent;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * The form's side handling responses: moving them between the form's
 * stages, one or many at once, and notes. Each goes into the response's
 * history; none is shown to the respondent.
 */
class FormResponseActionsService
{
    /**
     * @param  list<int>  $ids
     */
    public function move(Form $form, array $ids, string $stage, ?string $note, Authenticatable $user): ServiceResult
    {
        $names = array_column($form->stages(), 'name', 'id');

        if (! isset($names[$stage])) {
            return ServiceResult::failure('Choose one of the form\'s stages.', 422, ['stage' => 'Choose one of the form\'s stages.']);
        }

        $responses = FormResponse::query()->where('form_id', $form->id)->whereIn('id', $ids)->get();
        $moved = 0;

        DB::transaction(function () use ($responses, $stage, $note, $user, &$moved): void {
            foreach ($responses as $response) {
                if ($response->stage === $stage) {
                    continue;
                }

                $from = $response->stage;
                $response->stage = $stage;
                $response->save();
                FormResponseEvent::query()->create([
                    'form_response_id' => $response->id,
                    'kind' => 'stage',
                    'user_id' => (int) $user->getAuthIdentifier(),
                    'data' => array_filter(['from' => $from, 'to' => $stage, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null]),
                ]);
                $moved++;
            }
        });

        return ServiceResult::success(['moved' => $moved], $moved === 1 ? "Moved 1 response to {$names[$stage]}." : "Moved {$moved} responses to {$names[$stage]}.");
    }

    public function note(FormResponse $response, string $text, Authenticatable $user): ServiceResult
    {
        $event = FormResponseEvent::query()->create([
            'form_response_id' => $response->id,
            'kind' => 'note',
            'user_id' => (int) $user->getAuthIdentifier(),
            'data' => ['text' => mb_substr(trim($text), 0, 2000)],
        ]);

        return ServiceResult::success($event, 'Added the note.');
    }
}
