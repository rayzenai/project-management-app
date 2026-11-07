<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormDraft;
use App\Models\FormFile;
use App\Models\FormResponse;
use App\Models\FormResponseEvent;
use App\Models\Member;
use App\Support\Forms\FormAnswers;
use App\Support\Forms\FormSchema;
use App\Support\ServiceResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Filling a form in: the draft saved as people type, clearing it, and
 * Submit, which checks every answer (FormAnswers::check), keeps one
 * response per person (or organization), takes the draft's files, clears
 * the draft and, when the form says so, makes a task. A test (the form's
 * side trying it, against the draft) is checked the same way and kept
 * nowhere. The requests have checked canAnswerForm (or canEditForm for a
 * test).
 */
class FormFillService
{
    public function __construct(private MakeTaskFromResponseService $tasks) {}

    public static function respondentKey(Form $form, Member $member, bool $test): string
    {
        return $test ? 't:'.$member->id : $form->respondentKeyFor($member);
    }

    /** @return array<string, mixed> */
    public static function schemaFor(Form $form, bool $test): array
    {
        return $test ? $form->draft : ($form->liveSchema() ?? []);
    }

    public function saveDraft(Form $form, Member $member, mixed $answers, bool $test, Authenticatable $user): ServiceResult
    {
        $draft = FormDraft::query()->firstOrNew(['form_id' => $form->id, 'respondent_key' => self::respondentKey($form, $member, $test)]);
        $draft->answers = FormAnswers::draft(self::schemaFor($form, $test), $answers);
        $draft->form_version_id = $test ? null : $form->published_version_id;
        $draft->updated_by = (int) $user->getAuthIdentifier();
        $draft->save();

        return ServiceResult::success(['saved_at' => $draft->updated_at?->toIso8601String()], 'Saved.');
    }

    public function clearDraft(Form $form, Member $member, bool $test): ServiceResult
    {
        FormDraft::query()->where('form_id', $form->id)->where('respondent_key', self::respondentKey($form, $member, $test))->delete();

        return ServiceResult::success(null, 'Cleared your draft.');
    }

    public function submit(Form $form, Member $member, mixed $answers, bool $test, Authenticatable $user): ServiceResult
    {
        $key = self::respondentKey($form, $member, $test);
        $existing = $test ? null : FormResponse::query()->where('form_id', $form->id)->where('respondent_key', $key)->first();

        if ($existing !== null && ! $form->setting('allow_edit', true)) {
            return ServiceResult::failure('You have already sent your response.', 422, ['answers' => 'You have already sent your response.']);
        }

        if (! $test && $existing === null && $form->response_limit !== null && $form->countedResponses() >= $form->response_limit) {
            return ServiceResult::failure('This form has all the responses it takes.', 422, ['answers' => 'This form has all the responses it takes.']);
        }

        $schema = self::schemaFor($form, $test);
        $ownFiles = fn (array $ids, string $question): array => array_values(FormFile::query()
            ->where('form_id', $form->id)
            ->where('purpose', FormFile::PURPOSE_ANSWER)
            ->where('block_id', $question)
            ->whereIn('id', $ids)
            ->where(fn ($q) => $q->where('respondent_key', $key)->when($existing !== null, fn ($r) => $r->orWhere('form_response_id', $existing?->id)))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all());
        $checked = FormAnswers::check($schema, $answers, $ownFiles);

        if ($checked['errors'] !== []) {
            // One entry per question (`answers.q_x`, `answers.q_group.0.q_y`), so the
            // page can show each beside its question.
            $errors = [];

            foreach ($checked['errors'] as $path => $message) {
                $errors["answers.{$path}"] = $message;
            }

            return ServiceResult::failure('Some answers need another look.', 422, $errors);
        }

        if ($test) {
            return ServiceResult::success(['reference' => 'TEST', 'test' => true], 'Every answer passed. This was a test, so nothing was kept.');
        }

        try {
            $response = DB::transaction(function () use ($form, $member, $key, $existing, $checked, $schema, $user): FormResponse {
                Form::query()->whereKey($form->id)->lockForUpdate()->first();
                $now = CarbonImmutable::now();
                $response = $existing ?? FormResponse::query()->where('form_id', $form->id)->where('respondent_key', $key)->first();

                if ($response === null) {
                    $response = new FormResponse;
                    $response->form_id = $form->id;
                    $response->reference = FormResponse::newReference();
                    $response->respondent_key = $key;
                    $response->member_id = $member->id;
                    $response->organization_id = $member->organization_id;
                    $response->sender = $this->sender($member);
                    $response->stage = $form->stages()[0]['id'];
                    $response->submitted_by = (int) $user->getAuthIdentifier();
                    $response->submitted_at = $now;
                    $kind = 'submitted';
                } else {
                    $kind = 'edited';
                }

                $response->form_version_id = (int) $form->published_version_id;
                $response->answers = $checked['answers'];
                $response->updated_by = (int) $user->getAuthIdentifier();
                $response->is_test = false;
                $response->save();

                FormFile::query()->where('form_id', $form->id)->whereIn('id', self::fileIds($schema, $checked['answers']))->update(['form_response_id' => $response->id]);
                FormResponseEvent::query()->create(['form_response_id' => $response->id, 'kind' => $kind, 'user_id' => (int) $user->getAuthIdentifier(), 'data' => ['by' => $this->sender($member)]]);
                FormDraft::query()->where('form_id', $form->id)->where('respondent_key', $key)->delete();

                return $response;
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::failure("Your response didn't go through. Try again; your answers are still here.", 500);
        }

        if ($response->wasRecentlyCreated) {
            $this->tasks->execute($response);
        }

        return ServiceResult::success($response, $response->wasRecentlyCreated
            ? 'Sent. Your reference is '.$response->reference.'.'
            : 'Saved your changes to '.$response->reference.'.');
    }

    /** @return array{name: string, designation: string|null, organization: string|null} */
    private function sender(Member $member): array
    {
        $member->loadMissing(['designation:id,name', 'organization:id,name']);

        return ['name' => $member->name, 'designation' => $member->designation?->name, 'organization' => $member->organization?->name];
    }

    /**
     * Every file id a response's answers hold, inside repeating groups too.
     *
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $answers
     * @return list<int>
     */
    public static function fileIds(array $schema, array $answers): array
    {
        $ids = [];

        foreach (FormSchema::allQuestions($schema) as $id => $question) {
            if ($question['type'] !== 'file') {
                continue;
            }

            $values = isset($question['group'])
                ? array_map(fn (mixed $entry): mixed => is_array($entry) ? ($entry[$id] ?? null) : null, is_array($answers[$question['group']] ?? null) ? $answers[$question['group']] : [])
                : [$answers[$id] ?? null];

            foreach ($values as $value) {
                array_push($ids, ...array_values(array_filter(is_array($value) ? $value : [], 'is_int')));
            }
        }

        return array_values(array_unique($ids));
    }
}
