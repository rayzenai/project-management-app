<?php

namespace App\Services\Git;

use App\Models\GitCommit;
use App\Support\Git\DiffStats;

/**
 * Counts one commit's lines from the forge: a merge is only marked, any other
 * commit gets the forge's totals and, unless its diff is over the cap, the
 * diff classified into code, comment, blank, docs and generated lines.
 * A forge failure other than a missing commit propagates, leaving the commit
 * uncounted for the next run.
 */
class CommitStats
{
    /** A diff over this many bytes (5 MB) is not read. */
    public const MAX_DIFF_BYTES = 5242880;

    public function compute(GitCommit $commit): void
    {
        $repo = $commit->repository;
        $client = ForgejoClient::for($repo->gitConnection);

        try {
            $data = $client->commit($repo->owner, $repo->name, $commit->sha);
        } catch (ForgejoException $e) {
            if ($e->status === 404) {
                $this->save($commit, [], 'missing');

                return;
            }

            throw $e;
        }

        $parents = is_array($data['parents'] ?? null) ? $data['parents'] : [];

        if (count($parents) > 1) {
            $this->save($commit, ['is_merge' => true], 'merge');

            return;
        }

        $stats = ForgeShapes::map($data, 'stats');
        $totals = [
            'additions' => self::count($stats['additions'] ?? null),
            'deletions' => self::count($stats['deletions'] ?? null),
        ];

        try {
            $diff = $client->commitDiff($repo->owner, $repo->name, $commit->sha, self::MAX_DIFF_BYTES);
        } catch (ForgejoException $e) {
            if ($e->status === 404) {
                $this->save($commit, [], 'missing');

                return;
            }

            throw $e;
        }

        if ($diff === null) {
            $this->save($commit, $totals, 'too_large');

            return;
        }

        $this->save($commit, $totals + DiffStats::classify($diff), 'ok');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function save(GitCommit $commit, array $attributes, string $status): void
    {
        $commit->forceFill($attributes + [
            'stats_status' => $status,
            'stats_computed_at' => now(),
        ])->save();
    }

    private static function count(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }
}
