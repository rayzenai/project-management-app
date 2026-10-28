<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A new member's email and password. Plain HTML and text views rather than
 * markdown, so a password with markdown characters (* _ `) arrives intact.
 * Sent synchronously; never queue it, or the password lands in the jobs table.
 */
class LoginDetails extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $sentBy,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your login for '.config('app.name').' Workspace',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.login-details',
            text: 'emails.login-details-text',
            with: ['loginUrl' => route('workspace.login')],
        );
    }
}
