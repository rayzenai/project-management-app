<?php

namespace App\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a queued job off the request's critical path. On the sync queue (where
 * production runs until a worker exists) the job runs once the response is
 * sent, like dispatchAfterResponse() but rescued: a failure is reported and
 * never breaks the app's other terminating callbacks. Dispatched inside a
 * transaction, it is only scheduled once that commits, so a rollback drops
 * it. A worker queue keeps the job's tries and backoff; if that queue is
 * down, the failure is reported and the caller carries on.
 */
final class Background
{
    public static function dispatch(ShouldQueue $job): void
    {
        if (config('queue.default') === 'sync') {
            DB::afterCommit(fn () => app()->terminating(fn () => rescue(fn () => Bus::dispatchSync($job))));

            return;
        }

        try {
            Bus::dispatch($job);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
