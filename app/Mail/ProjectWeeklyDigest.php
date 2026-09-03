<?php

namespace App\Mail;

use App\Models\ProjectDigestSubscriber;
use App\Models\Task;
use App\Services\ProjectProgress;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProjectWeeklyDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, Task>  $items
     */
    public function __construct(
        public ProjectDigestSubscriber $subscriber,
        public array $items,
    ) {}

    public function envelope(): Envelope
    {
        $day = ProjectProgress::meta()['day_number'];

        return new Envelope(
            subject: 'Nepal 100-Point Plan — Weekly Digest · Day '.$day,
        );
    }

    public function content(): Content
    {
        $categories = (array) config('government.categories');

        $grouped = collect($this->items)->groupBy('category')->map(function ($items, $slug) use ($categories) {
            return [
                'slug' => $slug,
                'label' => $categories[$slug]['label'] ?? ucfirst((string) $slug),
                'items' => $items->values()->all(),
            ];
        })->values()->all();

        return new Content(
            markdown: 'emails.plan-weekly-digest',
            with: [
                'grouped' => $grouped,
                'meta' => ProjectProgress::meta(),
                'unsubscribeUrl' => $this->subscriber->unsubscribeUrl(),
            ],
        );
    }
}
