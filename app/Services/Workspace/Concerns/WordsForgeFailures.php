<?php

namespace App\Services\Workspace\Concerns;

use App\Models\GitConnection;
use App\Services\Git\ForgejoException;
use App\Support\ServiceResult;
use Illuminate\Support\Facades\Log;

trait WordsForgeFailures
{
    /**
     * A forge call that failed for no reason the person can fix: an
     * unreachable forge (its cause logged with the token masked) or an
     * answer the caller doesn't expect.
     */
    private function forgeFailure(ForgejoException $e, GitConnection $connection): ServiceResult
    {
        if ($e->status === 0) {
            Log::warning('Forge unreachable.', ['url' => $connection->base_url, 'cause' => $e->causeMessage($connection->token)]);
        }

        return $e->status === 0 || $e->status >= 500
            ? ServiceResult::failure("Couldn't reach the forge at {$connection->base_url}.", 422)
            : ServiceResult::failure("The forge answered {$e->status}. Try again shortly.", 422);
    }
}
