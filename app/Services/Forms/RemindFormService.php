<?php

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\User;
use App\Notifications\FormReminder;
use App\Queries\Forms\FormRespondentsQuery;
use App\Support\ServiceResult;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Remind those who haven't answered (FormReminder, also emailed): each
 * person; for a data call, the people FormRespondentsQuery::notifyTargets
 * names for each organization that hasn't. Never the person reminding,
 * and nobody more than once per `forms.remind_every_hours` for one form.
 */
class RemindFormService
{
    public function __construct(private FormRespondentsQuery $respondents) {}

    public function execute(Form $form, Authenticatable $user): ServiceResult
    {
        if (! $form->isOpenNow()) {
            return ServiceResult::failure('Only an open form sends reminders.', 422);
        }

        $pending = $this->respondents->pending($form);
        $targets = $form->answersByOrganization()
            ? $this->respondents->notifyTargets($form, array_column($pending['organizations'], 'id'))
            : $this->respondents->notifyTargets($form, null, $this->pendingMemberIds($form));
        $actor = $user instanceof User ? $user->name : 'Someone';
        $sent = 0;

        foreach ($targets as $person) {
            $recipient = $person->user;

            if ($recipient === null || $recipient->id === (int) $user->getAuthIdentifier() || $this->remindedRecently($recipient, $form)) {
                continue;
            }

            $recipient->notify(new FormReminder($form, $actor));
            $sent++;
        }

        return ServiceResult::success(['sent' => $sent], match (true) {
            $sent === 0 => 'Nobody needed a reminder just now: everyone was reminded today or has answered.',
            $sent === 1 => 'Reminded 1 person.',
            default => "Reminded {$sent} people.",
        });
    }

    /** @return list<int> */
    private function pendingMemberIds(Form $form): array
    {
        $answered = $form->responses()->whereNotNull('respondent_key')->pluck('member_id')->filter()->map(fn ($id): int => (int) $id)->all();

        return array_values(array_diff(
            $this->respondents->ownPeople($form)->pluck('members.id')->merge($this->respondents->otherPeople($form)->pluck('members.id'))->map(fn ($id): int => (int) $id)->all(),
            $answered,
        ));
    }

    private function remindedRecently(User $recipient, Form $form): bool
    {
        return $recipient->notifications()
            ->where('type', FormReminder::class)
            ->where('created_at', '>=', now()->subHours((int) config('forms.remind_every_hours', 24)))
            ->get(['data'])
            ->contains(fn ($notification): bool => ($notification->data['form']['id'] ?? null) === $form->id);
    }
}
