<?php

use App\Console\Commands\SendEmailUpdates;
use App\Mail\UpdatesMail;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;
use App\Notifications\AddedToProjectTeam;
use App\Notifications\MentionedInComment;
use App\Notifications\TaskAssigned;
use App\Notifications\TaskStatusChanged;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Exception\TransportException;

/*
 * Right-away email: everything given to someone inside a few minutes goes
 * out as one email once it goes quiet (or has waited long enough), never
 * twice, never what they already read in the app, and never to someone who
 * turned it off or can no longer sign in.
 */

beforeEach(function () {
    Mail::fake();
    Carbon::setTestNow('2026-11-03 10:00:00');

    $assigner = User::factory()->create(['name' => 'Mina Thapa']);
    $this->assigner = Member::factory()->linkedTo($assigner)->create();

    $user = User::factory()->create(['name' => 'Bibek Joshi', 'email' => 'bibek@example.test']);
    $this->member = Member::factory()->linkedTo($user)->create();
    $this->user = $user;
});

/** Mina gives the member a task, now. */
function euAssign(Member $member, Member $by, string $title = 'Collect revenue data'): Task
{
    $task = Task::factory()->create(['title' => $title, 'status' => 'not_started']);

    test()->actingAs($by->user);
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $member->id]);

    return $task;
}

function euRunAt(string $time, array $options = []): void
{
    Carbon::setTestNow($time);
    test()->artisan('workspace:send-email-updates', $options)->assertSuccessful();
}

/** @return list<string> the mail's item titles */
function euTitles(UpdatesMail $mail): array
{
    return array_column($mail->items, 'title');
}

it('waits for quiet, then sends one email', function () {
    euAssign($this->member, $this->assigner);

    euRunAt('2026-11-03 10:01:00');
    Mail::assertNothingSent();

    euRunAt('2026-11-03 10:03:00');
    Mail::assertSent(UpdatesMail::class, 1);
    Mail::assertSent(UpdatesMail::class, function (UpdatesMail $mail) {
        return $mail->hasTo('bibek@example.test')
            && $mail->envelope()->subject === 'Task assigned: Collect revenue data'
            && $mail->items[0]['line'] === 'Mina Thapa assigned you “Collect revenue data”.'
            && str_starts_with($mail->items[0]['url'], url('/workspace/projects/'));
    });
});

it('groups what arrives a minute apart into one email once it goes quiet', function () {
    euAssign($this->member, $this->assigner, 'First');
    Carbon::setTestNow('2026-11-03 10:01:00');
    euAssign($this->member, $this->assigner, 'Second');
    euRunAt('2026-11-03 10:01:30');
    Carbon::setTestNow('2026-11-03 10:02:00');
    euAssign($this->member, $this->assigner, 'Third');

    euRunAt('2026-11-03 10:03:00');
    Mail::assertNothingSent();

    euRunAt('2026-11-03 10:04:00');
    Mail::assertSent(UpdatesMail::class, 1);
    Mail::assertSent(UpdatesMail::class, fn (UpdatesMail $mail) => $mail->envelope()->subject === '3 updates for you'
        && euTitles($mail) === ['Task assigned: First', 'Task assigned: Second', 'Task assigned: Third']);
});

it('sends once the oldest has waited five minutes, even when more keeps coming', function () {
    foreach (range(0, 5) as $minute) {
        Carbon::setTestNow(sprintf('2026-11-03 10:%02d:00', $minute));
        euAssign($this->member, $this->assigner, "Task {$minute}");
        euRunAt(sprintf('2026-11-03 10:%02d:00', $minute));

        if ($minute < 5) {
            Mail::assertNothingSent();
        }
    }

    Mail::assertSent(UpdatesMail::class, 1);
    Mail::assertSent(UpdatesMail::class, fn (UpdatesMail $mail) => count($mail->items) === 6);
});

it('leaves out what was read in the app before the run', function () {
    euAssign($this->member, $this->assigner, 'Read already');
    euAssign($this->member, $this->assigner, 'Still unread');

    $this->user->notifications()->get()
        ->first(fn ($n) => $n->data['task']['title'] === 'Read already')
        ->markAsRead();

    euRunAt('2026-11-03 10:03:00');

    Mail::assertSent(UpdatesMail::class, 1);
    Mail::assertSent(UpdatesMail::class, fn (UpdatesMail $mail) => euTitles($mail) === ['Task assigned: Still unread']);
});

it('sends nothing when everything was read in the app', function () {
    euAssign($this->member, $this->assigner);
    $this->user->unreadNotifications->markAsRead();

    euRunAt('2026-11-03 10:03:00');

    Mail::assertNothingSent();
});

it('sends nothing to someone who turned right-away email off, and marks it handled', function () {
    $this->user->preferences()->create(['email_notifications' => false]);
    euAssign($this->member, $this->assigner);

    euRunAt('2026-11-03 10:03:00');
    Mail::assertNothingSent();
    expect(DB::table('notifications')->whereNull('email_handled_at')->count())->toBe(0);

    // Turning it back on later never sends what came while it was off.
    $this->user->preferences()->update(['email_notifications' => true]);
    euRunAt('2026-11-03 10:10:00');
    Mail::assertNothingSent();
});

it('sends nothing to a deactivated member', function () {
    euAssign($this->member, $this->assigner);
    $this->member->update(['is_active' => false]);

    euRunAt('2026-11-03 10:03:00');

    Mail::assertNothingSent();
    expect(DB::table('notifications')->whereNull('email_handled_at')->count())->toBe(0);
});

it('sends nothing more on a second run', function () {
    euAssign($this->member, $this->assigner);

    euRunAt('2026-11-03 10:03:00');
    euRunAt('2026-11-03 10:04:00');
    euRunAt('2026-11-03 10:30:00');

    Mail::assertSent(UpdatesMail::class, 1);
});

it('never sends what another run claimed after this one read it', function () {
    euAssign($this->member, $this->assigner);

    // An overlapping run claims the rows just after this run read them.
    $claimed = false;
    DB::listen(function ($query) use (&$claimed) {
        if (! $claimed && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'notifications')) {
            $claimed = true;
            DB::table('notifications')->update(['email_handled_at' => now()]);
        }
    });

    euRunAt('2026-11-03 10:03:00');

    expect($claimed)->toBeTrue();
    Mail::assertNothingSent();
});

it('sends and marks nothing with --pretend', function () {
    euAssign($this->member, $this->assigner);

    euRunAt('2026-11-03 10:03:00', ['--pretend' => true]);

    Mail::assertNothingSent();
    expect(DB::table('notifications')->whereNull('email_handled_at')->count())->toBe(1);

    euRunAt('2026-11-03 10:04:00');
    Mail::assertSent(UpdatesMail::class, 1);
});

it('never emails notifications from before the migration', function () {
    // Rolled back and run again in migration order: the scan's index first.
    $index = require database_path('migrations/2026_11_03_110200_index_notifications_for_email_updates.php');
    $migration = require database_path('migrations/2026_11_03_110100_add_email_settings.php');
    $index->down();
    $migration->down();

    euAssign($this->member, $this->assigner);

    $migration->up();
    $index->up();

    euRunAt('2026-11-03 10:03:00');

    Mail::assertNothingSent();
});

it('emails the kinds given to you, mentions and joining, not status changes', function () {
    $project = Project::factory()->create(['title' => 'Resources']);
    $task = Task::factory()->create(['title' => 'Server room checklist']);

    $this->user->notify(new MentionedInComment($task, 'Mina Thapa', 'can you check this'));
    Carbon::setTestNow('2026-11-03 10:00:10');
    $this->user->notify(new AddedToProjectTeam($project, 'Mina Thapa'));
    Carbon::setTestNow('2026-11-03 10:00:20');
    $this->user->notify(new TaskStatusChanged($task, 'Done', 'Mina Thapa'));

    euRunAt('2026-11-03 10:03:00');

    Mail::assertSent(UpdatesMail::class, fn (UpdatesMail $mail) => euTitles($mail) === [
        'You were mentioned: Server room checklist',
        'Added to a project: Resources',
    ]);
    expect(SendEmailUpdates::KINDS)->toContain(TaskAssigned::class)
        ->and(SendEmailUpdates::KINDS)->not->toContain(TaskStatusChanged::class);
});

it('does not notify you of a task you gave yourself', function () {
    euAssign($this->member, $this->member);

    expect($this->user->notifications()->count())->toBe(0);
});

it('keeps the rows for the next run when the mail server fails', function () {
    euAssign($this->member, $this->assigner);

    breakMailTransport();

    euRunAt('2026-11-03 10:03:00');

    expect(DB::table('notifications')->whereNull('email_handled_at')->count())->toBe(1);

    Mail::fake();
    euRunAt('2026-11-03 10:04:00');
    Mail::assertSent(UpdatesMail::class, 1);
});

it('stops the run at the first failure to reach the mail server, giving back only what it took', function (Throwable $failure) {
    Exceptions::fake();
    Log::spy();

    foreach (['Rabin Ghimire', 'Asha Rai', 'Mina Rai'] as $name) {
        $other = Member::factory()->linkedTo(User::factory()->create(['name' => $name]))->create();
        euAssign($other, $this->assigner);
    }

    $asked = fakeMailServer(fn (): Throwable => $failure);
    euRunAt('2026-11-03 10:03:00');

    // One try, not one per person: the next minute tries again.
    expect($asked)->toHaveCount(1)
        ->and(DB::table('notifications')->whereNull('email_handled_at')->count())->toBe(3);
    Exceptions::assertReportedCount(1);
    Log::shouldNotHaveReceived('warning');
})->with([
    'no connection' => fn () => new TransportException('Connection could not be established with host "smtp.example.test:587"'),
    'a timeout' => fn () => new TransportException('Connection to "smtp.example.test:587" timed out.'),
    'TLS' => fn () => new TransportException('Unable to connect with STARTTLS.'),
    'a refused login' => fn () => new TransportException('Failed to authenticate on SMTP server with username "no-reply".', 535),
    'a "try later" reply' => fn () => mailRefusal(451),
]);

it('treats a refused address as handled: one warning with the person, never tried again, and goes on', function () {
    Exceptions::fake();
    Log::spy();
    euAssign($this->member, $this->assigner);
    Carbon::setTestNow('2026-11-03 10:00:10');
    $rabin = Member::factory()->linkedTo(User::factory()->create(['name' => 'Rabin Ghimire', 'email' => 'rabin@example.test']))->create();
    euAssign($rabin, $this->assigner);

    $asked = fakeMailServer(fn (string $to): ?Throwable => $to === 'bibek@example.test' ? mailRefusal() : null);
    euRunAt('2026-11-03 10:03:00');

    expect($asked->getArrayCopy())->toBe(['bibek@example.test', 'rabin@example.test'])
        ->and(DB::table('notifications')->whereNull('email_handled_at')->count())->toBe(0);
    Exceptions::assertNothingReported();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, "user #{$this->user->id}")
        && str_contains($message, '550')
        && ! str_contains($message, 'bibek@example.test'));

    // The same unread rows, a minute later: nothing is sent again.
    euRunAt('2026-11-03 10:04:00');
    euRunAt('2026-11-03 10:30:00');
    expect($asked)->toHaveCount(2);
});

it('keeps the rows handled when something other than the mail server breaks an email, reports it once and goes on', function () {
    Exceptions::fake();
    Log::spy();

    foreach (['Rabin Ghimire', 'Asha Rai', 'Mina Rai'] as $name) {
        $other = Member::factory()->linkedTo(User::factory()->create(['name' => $name]))->create();
        euAssign($other, $this->assigner);
    }

    $asked = fakeMailServer(fn (): Throwable => new LogicException('A broken template'));
    euRunAt('2026-11-03 10:03:00');

    expect($asked)->toHaveCount(3)
        ->and(DB::table('notifications')->whereNull('email_handled_at')->count())->toBe(0);
    Exceptions::assertReportedCount(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '2 more'));
});

it('gives up on a mail server that stops answering after 15 seconds, or MAIL_TIMEOUT', function () {
    // A real SMTP mailer, as production builds it (the tests' is faked).
    $timeout = fn (): float => (new MailManager(app()))->mailer('smtp')->getSymfonyTransport()->getStream()->getTimeout();

    expect($timeout())->toBe(15.0);

    config(['mail.mailers.smtp.timeout' => 5.0]);
    expect($timeout())->toBe(5.0);
});

it('indexes the notifications the scan reads', function () {
    expect(Schema::hasIndex('notifications', ['email_handled_at', 'created_at']))->toBeTrue();
});

it('runs every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'workspace:send-email-updates'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('renders the email with the greeting, the items and the way to change it', function () {
    $mail = new UpdatesMail($this->user, [
        ['title' => 'Task assigned: Collect revenue data', 'line' => 'Mina Thapa assigned you “Collect revenue data”.', 'url' => url('/workspace/projects/p/tasks/t')],
    ]);

    $html = $mail->render();

    expect($html)->toContain('Hello, Bibek.')
        ->toContain('Task assigned: Collect revenue data')
        ->toContain('Mina Thapa assigned you “Collect revenue data”.')
        ->toContain(url('/workspace/projects/p/tasks/t'))
        ->toContain('Open my work')
        ->toContain(url('/workspace/my'))
        ->toContain('You get these when something is given to you, you are mentioned, or you join a project.')
        ->toContain(url('/workspace/settings/emails'));

    $mail->assertSeeInText('Hello, Bibek.');
    $mail->assertSeeInText(url('/workspace/settings/emails'));
});
