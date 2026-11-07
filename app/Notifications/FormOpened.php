<?php

namespace App\Notifications;

use App\Models\Form;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use Illuminate\Notifications\Notification;

// in-app (database) only, synchronous; SendEmailUpdates mails it right away
class FormOpened extends Notification
{
    use BuildsWorkspaceNotification;

    public function __construct(public Form $form, public string $actorName) {}

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        $by = $this->form->closes_at === null ? '' : ' by '.$this->form->closes_at->format('j M Y, g:i a');

        return [
            'kind' => 'form_opened',
            'title' => $this->form->title,
            'body' => "{$this->actorName} asks you to fill in {$this->form->title}{$by}.",
            'action' => 'asks you to fill it in'.$by,
            'task' => null,
            'form' => ['id' => $this->form->id, 'code' => $this->form->code, 'title' => $this->form->title],
            'actor' => ['name' => $this->actorName],
            'url' => "/f/{$this->form->code}",
        ];
    }
}
