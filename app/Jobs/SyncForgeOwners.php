<?php

namespace App\Jobs;

use App\Models\GitConnection;
use App\Models\Member;
use App\Services\Git\ForgejoException;
use App\Services\Git\ForgeMembership;
use App\Services\Git\TooManyForgeRemovals;
use App\Support\Background;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Puts a forge organization's Owners team in step with its office admins
 * (ForgeMembership::syncOwners) once a member update changed who they are.
 * It runs off the request: a forge failure is logged without the token and
 * never undoes the update, and the nightly reconcile runs the same sync.
 */
class SyncForgeOwners implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public GitConnection $gitConnection) {}

    /**
     * The organizations whose office admins the member's update just changed:
     * theirs when the flag flipped or an office admin was deactivated or
     * reactivated, and the one they left when an office admin moved. Read
     * right after the update, before anything else saves the member.
     *
     * @return list<int>
     */
    public static function organizationsChangedBy(Member $member): array
    {
        if (! $member->wasChanged('is_office_admin')
            && ! ($member->is_office_admin && $member->wasChanged(['organization_id', 'is_active']))) {
            return [];
        }

        $left = $member->getPrevious()['organization_id'] ?? null;

        return array_values(array_unique(array_filter([$member->organization_id, is_numeric($left) ? (int) $left : null])));
    }

    /**
     * @param  list<int>  $organizationIds
     */
    public static function dispatchFor(array $organizationIds): void
    {
        if ($organizationIds === []) {
            return;
        }

        foreach (GitConnection::query()->whereIn('organization_id', $organizationIds)->orderBy('id')->get() as $connection) {
            Background::dispatch(new self($connection));
        }
    }

    public function handle(ForgeMembership $membership): void
    {
        try {
            $membership->syncOwners($this->gitConnection);
        } catch (TooManyForgeRemovals) {
            // Logged where it was held back; a person looks.
        } catch (ForgejoException $e) {
            $context = ['owner' => $this->gitConnection->owner, 'status' => $e->status];
            if ($e->status === 0) {
                $context['url'] = $this->gitConnection->base_url;
                $context['cause'] = $e->causeMessage((string) $this->gitConnection->token);
            }
            Log::warning("Couldn't sync the forge organization's owners.", $context);
        }
    }
}
