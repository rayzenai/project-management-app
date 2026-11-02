<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * The right-away email: what was given to someone in the last few minutes
 * (SendEmailUpdates), one item per in-app notification. Plain HTML and text
 * views on the shared emails.layout, sent by the scheduled command.
 */
class UpdatesMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{title: string, line: string, url: string}>  $items  each `url` absolute
     */
    public function __construct(
        public User $user,
        public array $items,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: count($this->items) === 1
                ? $this->items[0]['title']
                : count($this->items).' updates for you',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.updates',
            text: 'emails.updates-text',
            with: [
                'firstName' => Str::before(trim($this->user->name), ' '),
                'myWorkUrl' => url('/workspace/my'),
                'settingsUrl' => url('/workspace/settings/emails'),
            ],
        );
    }
}
