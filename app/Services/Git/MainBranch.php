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
 * RULE names every field of Forgejo 15's EditBranchProtectionOption: an
 * edit changes only the fields it is sent, so a field left out (unprotected
 * file patterns, say, which let a push through untouched) could be loosened
 * on the forge and stay so.
 *
 * ensure() reads the rule first and writes it only when it differs (every
 * write makes the forge test every open pull request into the branch again,
 * holding their merges meanwhile), and creates it when the forge has none.
 * The access sync calls it for every repository, so whatever was changed on
 * the forge comes back within minutes.
 */
final class MainBranch
{
    public const RULE = [
        'enable_push' => false,
        'enable_push_whitelist' => false,
        'push_whitelist_usernames' => [],
        'push_whitelist_teams' => [],
        'push_whitelist_deploy_keys' => false,
        'enable_merge_whitelist' => false,
        'merge_whitelist_usernames' => [],
        'merge_whitelist_teams' => [],
        'enable_status_check' => true,
        'status_check_contexts' => ['*'],
        'required_approvals' => 1,
        'enable_approvals_whitelist' => false,
        'approvals_whitelist_username' => [],
        'approvals_whitelist_teams' => [],
        'block_on_rejected_reviews' => true,
        'block_on_official_review_requests' => false,
        'block_on_outdated_branch' => false,
        'dismiss_stale_approvals' => true,
        'ignore_stale_approvals' => false,
        'require_signed_commits' => false,
        'protected_file_patterns' => '',
        'unprotected_file_patterns' => '',
        'apply_to_admins' => true,
    ];

    /**
     * @return bool true once the rule is on the branch; false, writing
     *              nothing, while the repository has no such branch (a rule
     *              on it would stop anyone pushing its first commit)
     *
     * @throws ForgejoException
     */
    public static function ensure(ForgejoClient $client, string $owner, string $name, string $branch = 'main'): bool
    {
        $current = $client->branchProtection($owner, $name, $branch);

        if ($current === null) {
            if (! $client->hasBranch($owner, $name, $branch)) {
                return false;
            }

            try {
                $client->protectBranch($owner, $name, ['rule_name' => $branch, 'branch_name' => $branch, ...self::RULE]);

                return true;
            } catch (ForgejoException $e) {
                // Another sync made it first: the forge refuses a second one.
                $current = in_array($e->status, [403, 409, 422], true) ? $client->branchProtection($owner, $name, $branch) : null;

                if ($current === null) {
                    throw $e;
                }
            }
        }

        if (! self::holds($current)) {
            $client->updateBranchProtection($owner, $name, $branch, self::RULE);
        }

        return true;
    }

    /**
     * Whether the forge's rule (api.BranchProtection) is the tracker's. An
     * empty list may come back as null.
     *
     * @param  array<string, mixed>  $current
     */
    public static function holds(array $current): bool
    {
        foreach (self::RULE as $field => $wanted) {
            $value = $current[$field] ?? null;

            if (is_array($wanted)) {
                $value = is_array($value) ? array_values($value) : [];
            }

            if ($value !== $wanted) {
                return false;
            }
        }

        return true;
    }
}
