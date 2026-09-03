<?php

namespace App\Console\Commands;

use App\Mail\ProjectWeeklyDigest;
use App\Models\ProjectDigestSubscriber;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendProjectWeeklyDigest extends Command
{
    protected $signature = 'digest:send-weekly {--pretend : Run without sending emails}';

    protected $description = 'Send the weekly Plan digest email to all subscribers due for delivery.';

    public function handle(): int
    {
        $subscribers = ProjectDigestSubscriber::dueForWeekly()->get();
        $since = now()->subDays(7);
        $sent = 0;
        $skipped = 0;

        foreach ($subscribers as $subscriber) {
            $query = Task::query()
                ->whereNotNull('status_updated_at')
                ->where('status_updated_at', '>=', $since)
                ->orderByDesc('status_updated_at');

            if (! empty($subscriber->categories)) {
                $query->whereIn('category', $subscriber->categories);
            }

            $items = $query->get();

            if ($items->isEmpty()) {
                $skipped++;

                continue;
            }

            if ($this->option('pretend')) {
                $this->line("Would send to {$subscriber->email} ({$items->count()} items)");
                $sent++;

                continue;
            }

            Mail::to($subscriber->email)->send(new ProjectWeeklyDigest($subscriber, $items->all()));
            $subscriber->last_sent_at = now();
            $subscriber->save();
            $sent++;
        }

        $message = "Plan weekly digest: sent {$sent}, skipped {$skipped} (no updates).";
        $this->info($message);
        Log::info($message);

        return self::SUCCESS;
    }
}
