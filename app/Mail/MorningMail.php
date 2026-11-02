<?php

namespace App\Mail;

use App\Models\User;
use App\Queries\MorningEmailQuery;
use App\Support\BikramSambat;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * The morning email (SendMorningEmails): what is late, due today and new
 * for someone (MorningEmailQuery), on the shared emails.layout. The subject
 * counts it: "Today: 3 tasks, 1 late".
 *
 * @phpstan-import-type MorningLists from MorningEmailQuery
 * @phpstan-import-type MorningRow from MorningEmailQuery
 */
class MorningMail extends Mailable
{
    use Queueable, SerializesModels;

    /** The email's sections in order: list => heading. */
    private const SECTIONS = ['late' => 'Late', 'today' => 'Today', 'new' => 'New for you'];

    /**
     * @param  MorningLists  $lists
     */
    public function __construct(
        public User $user,
        public CarbonImmutable $today,
        public array $lists,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.morning',
            text: 'emails.morning-text',
            with: [
                'subjectLine' => $this->subjectLine(),
                'firstName' => Str::before(trim($this->user->name), ' '),
                'dateLine' => $this->today->format('l, j F'),
                'bs' => BikramSambat::short($this->today),
                'sections' => $this->sections(),
                'myWorkUrl' => url('/workspace/my'),
                'settingsUrl' => url('/workspace/settings/emails'),
                // "9:00" for the configured "09:00".
                'sentAt' => CarbonImmutable::parse((string) config('project-management.emails.morning_at', '09:00'))->format('G:i'),
            ],
        );
    }

    /**
     * The non-empty lists as the email shows them: each task's title, link
     * and the grey line under it (`late` is said apart, in red).
     *
     * @return list<array{heading: string, rows: list<array{title: string, url: string, meta: string, late: string|null}>}>
     */
    private function sections(): array
    {
        $sections = [];

        foreach (self::SECTIONS as $list => $heading) {
            if ($this->lists[$list] !== []) {
                $sections[] = ['heading' => $heading, 'rows' => array_map(fn (array $row): array => $this->display($row, $list), $this->lists[$list])];
            }
        }

        return $sections;
    }

    /**
     * Late and today rows name the project; a new one says who gave it and
     * when it is due, as far as known (else the project).
     *
     * @param  MorningRow  $row
     * @return array{title: string, url: string, meta: string, late: string|null}
     */
    private function display(array $row, string $list): array
    {
        $meta = $row['project'];

        if ($list === 'new') {
            $known = array_filter([
                $row['from'] === null ? null : "From {$row['from']}",
                $row['due'] === null ? null : 'due '.CarbonImmutable::parse($row['due'])->format('D j M'),
            ]);
            $meta = $known === [] ? $row['project'] : implode(' · ', $known);
        }

        return [
            'title' => $row['title'],
            'url' => $row['url'],
            'meta' => $meta,
            'late' => $row['days_late'] > 0 ? $row['days_late'].' '.Str::plural('day', $row['days_late']).' late' : null,
        ];
    }

    private function subjectLine(): string
    {
        $count = count($this->lists['late']) + count($this->lists['today']) + count($this->lists['new']);
        $late = count($this->lists['late']);

        return "Today: {$count} ".Str::plural('task', $count).($late > 0 ? ", {$late} late" : '');
    }
}
