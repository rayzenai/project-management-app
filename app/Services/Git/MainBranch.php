<?php

namespace App\Services\Git;

/**
 * The tracker's rule for the main branch of every linked repository (its
 * default branch: `main` for every repository the tracker makes): nobody
 * pushes to it, repository admins (the maintainers) included, and a pull
 * request merges only once every check on it has passed and someone has
 * approved it. A repository with no checks merges nothing until it has
 * tests. Only the forge's own site administrators can still merge past it
 * (Forgejo lets them).
 *
 * ensure() writes the rule and creates it when the forge has none (a new
 * repository, or someone deleted it), so the access sync, which calls it
 * for every repository, puts it back within minutes whatever was changed
 * on the forge.
 */
final class MainBranch
{
    public const RULE = [
        'enable_push' => false,
        'enable_push_whitelist' => false,
        'push_whitelist_usernames' => [],
        'push_whitelist_teams' => [],
        'enable_merge_whitelist' => false,
        'enable_status_check' => true,
        'status_check_contexts' => ['*'],
        'required_approvals' => 1,
        'dismiss_stale_approvals' => true,
        'block_on_rejected_reviews' => true,
        'apply_to_admins' => true,
    ];

    /**
     * @throws ForgejoException
     */
    public static function ensure(ForgejoClient $client, string $owner, string $name, string $branch = 'main'): void
    {
        try {
            $client->updateBranchProtection($owner, $name, $branch, self::RULE);
        } catch (ForgejoException $e) {
            if ($e->status !== 404) {
                throw $e;
            }

            $client->protectBranch($owner, $name, ['rule_name' => $branch, 'branch_name' => $branch, ...self::RULE]);
        }
    }
}
