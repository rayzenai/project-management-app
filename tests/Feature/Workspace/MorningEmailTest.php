<?php

use App\Mail\MorningMail;
use App\Models\AttendanceSetting;
use App\Models\EmailDigest;
use App\Models\Holiday;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\Task;
use App\Models\User;
use App\Queries\MorningEmailQuery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/*
 * The morning email: at 9:00 on the organization's working days, one short
 * email to each person with something late, due today or new for them since
 * the last one. Never on a day off, never twice a day, never when nothing
 * waits. 2026-11-02 is a Monday; without attendance Sunday to Friday work.
 */

beforeEach(function () {
    Mail::fake();
    config(['project-management.super_admins' => ['boss@example.com']]);
    Carbon::setTestNow('2026-11-02 08:00:00');

    $this->project = Project::factory()->create(['key' => 'MO', 'title' => 'Resources']);
    $team = $this->project->ensureTeam();

    $assigner = User::factory()->create(['name' => 'Mina Thapa']);
    $this->assigner = Member::factory()->linkedTo($assigner)->create(['organization_id' => $this->project->organization_id]);

    $this->user = User::factory()->create(['name' => 'Bibek Joshi', 'email' => 'bibek@example.test']);
    $this->member = Member::factory()->linkedTo($this->user)->create(['organization_id' => $this->project->organization_id]);

    $team->members()->attach([$this->assigner->id, $this->member->id]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * An open task in the project given to `$on` (the member by default) by
 * `$by` (Mina by default), now.
 *
 * @param  array<string, mixed>  $attributes
 */
function morningTask(string $title, ?string $deadline = null, array $attributes = [], ?Member $on = null, ?Member $by = null): Task
{
    $task = Task::factory()->create([
        'project_id' => test()->project->id,
        'title' => $title,
        'status' => 'not_started',
        'deadline_type' => 'rolling',
        'deadline_at' => $deadline,
        ...$attributes,
    ]);

    test()->actingAs(($by ?? test()->assigner)->user);
    ProjectAssignment::create(['task_id' => $task->id, 'member_id' => ($on ?? test()->member)->id]);

    return $task;
}

function morningRunAt(string $time, array $options = []): void
{
    Carbon::setTestNow($time);
    test()->artisan('workspace:send-morning-emails', $options)->assertSuccessful();
}

/** @return list<string> the titles of one list of the mail */
function morningTitles(MorningMail $mail, string $list): array
{
    return array_column($mail->lists[$list], 'title');
}

it('sends one email with what is late and what is due today', function () {
    morningTask('Collect revenue and expense data', '2026-10-31');
    morningTask('Server room checklist', '2026-11-02');

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertSent(MorningMail::class, 1);
    Mail::assertSent(MorningMail::class, fn (MorningMail $mail) => $mail->hasTo('bibek@example.test')
        && $mail->envelope()->subject === 'Today: 2 tasks, 1 late'
        && morningTitles($mail, 'late') === ['Collect revenue and expense data']
        && $mail->lists['late'][0]['days_late'] === 2
        && morningTitles($mail, 'today') === ['Server room checklist']
        && $mail->lists['new'] === []);
    expect(EmailDigest::query()->where('user_id', $this->user->id)->where('kind', 'morning')->value('sent_on')?->toDateString())
        ->toBe('2026-11-02');
});

it('says one task, and late only when something is', function () {
    morningTask('Server room checklist', '2026-11-02');

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertSent(MorningMail::class, fn (MorningMail $mail) => $mail->envelope()->subject === 'Today: 1 task');
});

it('sends nothing to someone with nothing waiting', function () {
    // Due later, given more than a day ago: nothing late, today or new.
    Carbon::setTestNow('2026-10-30 08:00:00');
    morningTask('Call center staff list', '2026-11-12');
    // Due today but done.
    morningTask('Old checklist', '2026-11-02', ['status' => 'done']);

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertNothingSent();
    expect(EmailDigest::query()->count())->toBe(0);
});

it('sends nothing on a Saturday', function () {
    morningTask('Collect revenue and expense data', '2026-10-31');

    morningRunAt('2026-11-07 09:00:00');

    Mail::assertNothingSent();
    expect(EmailDigest::query()->count())->toBe(0);

    morningRunAt('2026-11-08 09:00:00');
    Mail::assertSent(MorningMail::class, 1);
});

it('sends nothing on the organization\'s holiday, and on its next working day', function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->project->organization_id, 'workdays' => [7, 1, 2, 3, 4, 5]]);
    Holiday::factory()->create(['organization_id' => $this->project->organization_id, 'date' => '2026-11-02', 'name' => 'Tihar']);
    morningTask('Collect revenue and expense data', '2026-10-31');

    morningRunAt('2026-11-02 09:00:00');
    Mail::assertNothingSent();

    morningRunAt('2026-11-03 09:00:00');
    Mail::assertSent(MorningMail::class, 1);
});

it('follows the organization\'s own workdays with attendance on', function () {
    AttendanceSetting::factory()->create(['organization_id' => $this->project->organization_id, 'workdays' => [1, 2, 3, 4, 5]]);
    morningTask('Collect revenue and expense data', '2026-10-31');

    morningRunAt('2026-11-01 09:00:00'); // Sunday, not one of its workdays

    Mail::assertNothingSent();
});

it('sends nothing to someone who turned the morning email off', function () {
    $this->user->preferences()->create(['email_morning' => false]);
    morningTask('Collect revenue and expense data', '2026-10-31');

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertNothingSent();
    expect(EmailDigest::query()->count())->toBe(0);
});

it('still sends to someone who turned only right-away email off', function () {
    $this->user->preferences()->create(['email_notifications' => false]);
    morningTask('Collect revenue and expense data', '2026-10-31');

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertSent(MorningMail::class, 1);
});

it('sends nothing to a deactivated member', function () {
    morningTask('Collect revenue and expense data', '2026-10-31');
    $this->member->update(['is_active' => false]);

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertNothingSent();
});

it('sends once a day however often it runs', function () {
    morningTask('Collect revenue and expense data', '2026-10-31');

    morningRunAt('2026-11-02 09:00:00');
    morningRunAt('2026-11-02 09:00:30');
    morningRunAt('2026-11-02 14:00:00');

    Mail::assertSent(MorningMail::class, 1);
    expect(EmailDigest::query()->count())->toBe(1);

    morningRunAt('2026-11-03 09:00:00');
    Mail::assertSent(MorningMail::class, 2);
});

it('lists what was given since the last one as new, not what came yesterday before it', function () {
    // Sunday's email went late, at 10:00; one task came just before it, one after.
    Carbon::setTestNow('2026-11-01 09:30:00');
    morningTask('Before the last email', '2026-11-05');
    EmailDigest::query()->create(['user_id' => $this->user->id, 'kind' => 'morning', 'sent_on' => '2026-11-01', 'sent_at' => '2026-11-01 10:00:00']);
    Carbon::setTestNow('2026-11-01 15:00:00');
    morningTask('Call center staff list', '2026-11-05');

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertSent(MorningMail::class, fn (MorningMail $mail) => $mail->envelope()->subject === 'Today: 1 task'
        && morningTitles($mail, 'new') === ['Call center staff list']
        && $mail->lists['new'][0]['from'] === 'Mina Thapa'
        && $mail->lists['new'][0]['due'] === '2026-11-05');
});

it('reaches back to the last email over a weekend', function () {
    // Friday's email at 9:00, a task at 11:00, no email on Saturday.
    EmailDigest::query()->create(['user_id' => $this->user->id, 'kind' => 'morning', 'sent_on' => '2026-10-30', 'sent_at' => '2026-10-30 09:00:00']);
    Carbon::setTestNow('2026-10-30 11:00:00');
    morningTask('Call center staff list', '2026-11-12');

    morningRunAt('2026-11-01 09:00:00');

    Mail::assertSent(MorningMail::class, fn (MorningMail $mail) => morningTitles($mail, 'new') === ['Call center staff list']);
});

it('reaches back one day for new work before the first email', function () {
    Carbon::setTestNow('2026-10-31 08:00:00');
    morningTask('Two days ago', '2026-11-12');
    Carbon::setTestNow('2026-11-01 20:00:00');
    morningTask('Last night', '2026-11-12');

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertSent(MorningMail::class, fn (MorningMail $mail) => morningTitles($mail, 'new') === ['Last night']);
});

it('lists new work once, only while it is still open, on your list and not already late or today', function () {
    Carbon::setTestNow('2026-11-01 15:00:00');
    $twice = morningTask('Given twice', '2026-11-12');
    ProjectAssignment::query()->where('task_id', $twice->id)->forceDelete();
    ProjectAssignment::create(['task_id' => $twice->id, 'member_id' => $this->member->id]);
    $taken = morningTask('Taken back', '2026-11-12');
    ProjectAssignment::query()->where('task_id', $taken->id)->delete();
    morningTask('Finished already', '2026-11-12')->update(['status' => 'done']);
    morningTask('Due today', '2026-11-02');
    $snoozed = morningTask('Snoozed', '2026-11-12');
    ProjectAssignment::query()->where('task_id', $snoozed->id)->update(['snoozed_until' => '2026-11-09']);
    morningTask('Given by myself', '2026-11-12', by: $this->member);

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertSent(MorningMail::class, fn (MorningMail $mail) => morningTitles($mail, 'new') === ['Given twice']
        && morningTitles($mail, 'today') === ['Due today']
        && $mail->envelope()->subject === 'Today: 2 tasks');
});

it('leaves out who gave it when nobody was signed in to give it', function () {
    foreach (['Imported with a date' => '2026-11-05', 'Imported without a date' => null] as $title => $deadline) {
        // A minute apart: notifications in the same second have no order.
        Carbon::setTestNow($deadline === null ? '2026-11-01 15:01:00' : '2026-11-01 15:00:00');
        $task = Task::factory()->create(['project_id' => $this->project->id, 'title' => $title, 'status' => 'not_started', 'deadline_type' => 'rolling', 'deadline_at' => $deadline]);
        ProjectAssignment::create(['task_id' => $task->id, 'member_id' => $this->member->id]);
    }

    expect($this->user->notifications()->get()->pluck('data.actor.name')->unique()->all())->toBe(['Someone']);

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertSent(MorningMail::class, function (MorningMail $mail) {
        $html = $mail->render();

        return morningTitles($mail, 'new') === ['Imported with a date', 'Imported without a date']
            && array_column($mail->lists['new'], 'from') === [null, null]
            && ! str_contains($html, 'Someone')
            && str_contains($html, '>due Thu 5 Nov</span>')
            && str_contains($html, "Imported without a date</a>\n</td>");
    });
});

it('starts the next morning\'s new work where this one was claimed, however long the run took', function () {
    // Given at 9:03, while a long run that began at 9:00 had not reached them yet.
    Carbon::setTestNow('2026-11-02 09:03:00');
    morningTask('Call center staff list', '2026-11-12');

    $advanced = false;
    DB::listen(function ($query) use (&$advanced) {
        if (! $advanced && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'email_digests')) {
            $advanced = true;
            Carbon::setTestNow('2026-11-02 09:05:00');
        }
    });

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertSent(MorningMail::class, fn (MorningMail $mail) => morningTitles($mail, 'new') === ['Call center staff list']);
    expect($advanced)->toBeTrue()
        ->and(EmailDigest::query()->sole()->sent_at->format('H:i'))->toBe('09:05');

    // The next morning it is no longer new, and nothing else waits.
    morningRunAt('2026-11-03 09:00:00');
    Mail::assertSent(MorningMail::class, 1);
});

it('leaves your own to-dos out of today', function () {
    Carbon::setTestNow('2026-10-30 08:00:00');
    $task = morningTask('Call center staff list', '2026-11-12');
    $task->subtasks()->create(['user_id' => $this->user->id, 'body' => 'Ring the vendor', 'due_at' => '2026-11-02']);

    morningRunAt('2026-11-02 09:00:00');

    Mail::assertNothingSent();
});

it('uses Sunday to Friday for a super-admin in no organization', function () {
    $boss = User::factory()->create(['name' => 'Asha Rai', 'email' => 'boss@example.com']);
    $bossMember = Member::factory()->linkedTo($boss)->create(['organization_id' => null]);
    AttendanceSetting::factory()->create(['organization_id' => $this->project->organization_id, 'workdays' => [1, 2, 3, 4, 5]]);
    morningTask('Review the budget', '2026-10-30', on: $bossMember);

    morningRunAt('2026-11-07 09:00:00'); // Saturday
    Mail::assertNothingSent();

    morningRunAt('2026-11-08 09:00:00'); // Sunday: a working day for them, not for the office
    Mail::assertSent(MorningMail::class, 1);
    Mail::assertSent(MorningMail::class, fn (MorningMail $mail) => $mail->hasTo('boss@example.com'));
});

it('passes over a super-admin with no member at all', function () {
    User::factory()->create(['email' => 'boss@example.com']);

    morningRunAt('2026-11-08 09:00:00');

    Mail::assertNothingSent();
});

it('sends and records nothing with --pretend', function () {
    morningTask('Collect revenue and expense data', '2026-10-31');

    morningRunAt('2026-11-02 09:00:00', ['--pretend' => true]);

    Mail::assertNothingSent();
    expect(EmailDigest::query()->count())->toBe(0);

    morningRunAt('2026-11-02 09:01:00');
    Mail::assertSent(MorningMail::class, 1);
});

it('gives the day back when the mail server fails, and reports it once a run', function () {
    Exceptions::fake();
    Log::spy();
    $other = User::factory()->create(['name' => 'Rabin Ghimire']);
    $otherMember = Member::factory()->linkedTo($other)->create(['organization_id' => $this->project->organization_id]);
    $this->project->ensureTeam()->members()->attach($otherMember->id);
    morningTask('Collect revenue and expense data', '2026-10-31');
    morningTask('Server room checklist', '2026-10-31', on: $otherMember);
    breakMailTransport();

    morningRunAt('2026-11-02 09:00:00');

    expect(EmailDigest::query()->count())->toBe(0);
    Exceptions::assertReportedCount(1);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, '1 more'));

    Mail::fake();
    morningRunAt('2026-11-02 09:30:00');
    Mail::assertSent(MorningMail::class, 2);
});

it('runs at the morning hour without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'workspace:send-morning-emails'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 9 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

it('renders the greeting, the day, each list and the way to change it', function () {
    Carbon::setTestNow('2026-11-01 15:00:00');
    $late = morningTask('Collect revenue and expense data', '2026-10-31');
    morningTask('Server room checklist', '2026-11-02');
    $new = morningTask('Call center staff list', '2026-11-05');

    $today = CarbonImmutable::parse('2026-11-02 09:00:00');
    $mail = new MorningMail($this->user, $today, app(MorningEmailQuery::class)->for($this->user, $today));
    $html = $mail->render();

    expect($mail->envelope()->subject)->toBe('Today: 3 tasks, 1 late')
        ->and($html)->toContain('Good morning, Bibek.')
        ->toContain('Monday, 2 November · <span')
        ->toContain('कार्तिक १६</span>')
        ->toContain('Late')
        ->toContain('Resources · <span style="color:#c2372d;font-weight:500;">2 days late</span>')
        ->toContain('Server room checklist')
        ->toContain('New for you')
        ->toContain('From Mina Thapa · due Thu 5 Nov')
        ->toContain(url("/workspace/projects/{$this->project->slug}/tasks/{$late->slug}"))
        ->toContain(url("/workspace/projects/{$this->project->slug}/tasks/{$new->slug}"))
        ->toContain('Open my work')
        ->toContain(url('/workspace/my'))
        ->toContain('You get this on working days at 9:00, only when something is waiting for you.')
        ->toContain(url('/workspace/settings/emails'));

    $mail->assertSeeInText('Good morning, Bibek.');
    $mail->assertSeeInText('Monday, 2 November · कार्तिक १६');
    $mail->assertSeeInText('2 days late');
    $mail->assertSeeInText('From Mina Thapa · due Thu 5 Nov');
    $mail->assertSeeInText(url('/workspace/settings/emails'));
});
