<?php

namespace App\Notifications;

use App\Models\Document;
use App\Notifications\Concerns\BuildsWorkspaceNotification;
use Illuminate\Notifications\Notification;

// in-app (database) only, synchronous; SendEmailUpdates mails it right away
class DocumentShared extends Notification
{
    use BuildsWorkspaceNotification;

    public function __construct(public Document $document, public string $actorName, public string $permission) {}

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        $can = $this->permission === Document::ACCESS_EDIT ? 'You can edit it.' : 'You can view it.';

        return [
            'kind' => 'document_shared',
            'title' => $this->document->title,
            'body' => "{$this->actorName} shared {$this->document->title} with you. {$can}",
            'action' => "{$this->actorName} shared it with you",
            'task' => null,
            'document' => ['id' => $this->document->id, 'title' => $this->document->title],
            'actor' => ['name' => $this->actorName],
            'url' => "/workspace/documents/{$this->document->id}",
        ];
    }
}
