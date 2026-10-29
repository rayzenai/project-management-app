<?php

namespace App\Services\Git;

use RuntimeException;

/**
 * ForgeMembership held a run's removals back: more than a nightly drift
 * explains (ForgeMembership::REMOVAL_FLOOR, or REMOVAL_SHARE of the people
 * the tracker knows there), which points at a bug or a bad restore rather
 * than real departures. Nothing was removed; a person should look.
 */
class TooManyForgeRemovals extends RuntimeException
{
    public function __construct(public readonly int $removing, public readonly int $known)
    {
        parent::__construct("Held back: it would remove {$removing} of the {$known} people it knows there.");
    }
}
