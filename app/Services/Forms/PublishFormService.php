<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Notifications\FormOpened;
use App\Queries\Forms\FormRespondentsQuery;
use App\Support\ServiceResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Publish: the draft becomes a new version, which never changes, and the
 * live one. A draft form opens (or is scheduled to, before its open date).
 * Refused while FormPublishChecks finds a problem. The first publish may
 * tell its respondents (FormOpened, also emailed); a later one never does.
 */
class PublishFormService
{
    public function __construct(private FormPublishChecks $checks, private FormRespondentsQuery $respondents) {}

    public function execute(Form $form, Authenticatable $user, bool $notify): ServiceResult
    {
        $problems = $this->checks->problems($form);

        foreach ($problems as $problem) {
            if ($problem['level'] === 'error') {
                return ServiceResult::failure('Fix what is listed first: '.$problem['message'], 422, ['publish' => 'Fix what is listed first.']);
            }
        }

        if (! $this->checks->changedSincePublished($form)) {
            return ServiceResult::failure('Nothing has changed since it was published.', 422, ['publish' => 'Nothing has changed since it was published.']);
        }

        $first = $form->published_version_id === null;

        try {
            DB::transaction(function () use ($form, $user): void {
                Form::query()->whereKey($form->id)->lockForUpdate()->first();
                $number = (int) FormVersion::query()->where('form_id', $form->id)->max('number') + 1;

                $version = FormVersion::query()->create([
                    'form_id' => $form->id,
                    'number' => $number,
                    'schema' => $form->draft,
                    'published_by' => (int) $user->getAuthIdentifier(),
                    'published_at' => CarbonImmutable::now(),
                ]);

                $form->published_version_id = $version->id;

                if ($form->status === Form::STATUS_DRAFT) {
                    $form->status = Form::STATUS_OPEN;
                }

                $form->save();
            });
        } catch (Throwable $e) {
            report($e);

            return ServiceResult::failure("Couldn't publish {$form->title}. Try again.", 500);
        }

        $form->load('publishedVersion');

        if ($first && $notify) {
            $actor = $user instanceof User ? $user->name : 'Someone';

            foreach ($this->respondents->notifyTargets($form) as $person) {
                if ($person->user !== null && $person->user->id !== (int) $user->getAuthIdentifier()) {
                    $person->user->notify(new FormOpened($form, $actor));
                }
            }
        }

        return ServiceResult::success($form, $first ? "Published {$form->title}." : "Published the changes to {$form->title}.");
    }
}
