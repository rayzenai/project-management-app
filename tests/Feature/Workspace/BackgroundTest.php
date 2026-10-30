<?php

use App\Support\Background;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/*
 * Background::dispatch on the sync queue (production until a worker
 * exists): the job runs once the response is sent, and one dispatched
 * inside a transaction only once that transaction commits.
 */

/** Notes each run, by name. */
final class BackgroundTestProbe implements ShouldQueue
{
    use Queueable;

    /** @var list<string> */
    public static array $ran = [];

    public function __construct(public string $name) {}

    public function handle(): void
    {
        self::$ran[] = $this->name;
    }
}

beforeEach(function () {
    config(['queue.default' => 'sync']);
    BackgroundTestProbe::$ran = [];
});

it('runs a job after the response, unless the transaction it was dispatched in rolls back', function () {
    try {
        DB::transaction(function (): void {
            Background::dispatch(new BackgroundTestProbe('rolled back'));

            throw new RuntimeException('Undo.');
        });
    } catch (RuntimeException) {
        // The rollback is the point.
    }
    DB::transaction(fn () => Background::dispatch(new BackgroundTestProbe('committed')));
    Background::dispatch(new BackgroundTestProbe('outside a transaction'));

    expect(BackgroundTestProbe::$ran)->toBe([]);

    app()->terminate();

    expect(BackgroundTestProbe::$ran)->toBe(['committed', 'outside a transaction']);
});

it('drops a job dispatched in a nested transaction that rolls back, and keeps the outer one\'s', function () {
    DB::transaction(function (): void {
        Background::dispatch(new BackgroundTestProbe('outer'));

        try {
            DB::transaction(function (): void {
                Background::dispatch(new BackgroundTestProbe('inner'));

                throw new RuntimeException('Undo the inner one.');
            });
        } catch (RuntimeException) {
            // Only the savepoint is undone.
        }
    });

    app()->terminate();

    expect(BackgroundTestProbe::$ran)->toBe(['outer']);
});
