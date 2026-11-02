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
 * A link to set a password (PasswordLinkService): `reset` for a forgotten
 * one, `invite` for someone invited. Plain HTML and text views on the shared
 * emails.layout, with no email-settings footer (it is not a notification).
 * Sent synchronously; never queue it, or the link's token lands in the jobs
 * table.
 */
class SetPasswordLink extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $url  absolute; it carries the token
     * @param  'reset'|'invite'  $kind
     */
    public function __construct(
        public User $user,
        public string $url,
        public string $kind,
        public ?string $inviterName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.set-password',
            text: 'emails.set-password-text',
            with: [
                'subjectLine' => $this->subjectLine(),
                'body' => $this->body(),
            ],
        );
    }

    private function subjectLine(): string
    {
        if ($this->kind === 'reset') {
            return 'Set a new password';
        }

        return $this->inviterName === null
            ? 'You are invited to '.config('app.name')
            : "{$this->inviterName} invited you to ".config('app.name');
    }

    private function body(): string
    {
        $lasts = $this->lasts();

        return $this->kind === 'reset'
            ? "Someone asked to reset the password for {$this->user->email}. The link works for {$lasts}. If it wasn't you, ignore this email."
            : "Set your password to start. The link works for {$lasts}.";
    }

    /** How long the link works, from its broker's expiry: "60 minutes", "7 days". */
    private function lasts(): string
    {
        $minutes = (int) config('auth.passwords.'.($this->kind === 'reset' ? 'users' : 'invites').'.expire');

        if ($minutes % 1440 === 0) {
            return ($minutes / 1440).' '.Str::plural('day', $minutes / 1440);
        }

        return $minutes.' '.Str::plural('minute', $minutes);
    }
}
