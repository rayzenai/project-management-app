<?php

namespace App\Services\Git;

use App\Models\GitPullRequest;

/**
 * Forgejo objects that webhooks and the API share, mapped once for both
 * (ProcessGitDelivery and RepositorySync): api.Repository to a repository's
 * columns, and api.PullRequest to GitIngest's shape. Shapes are Forgejo 15's
 * (modules/structs/repo.go, pull.go).
 */
final class ForgeShapes
{
    /**
     * A repository's columns, each null when the forge left it out or empty.
     *
     * @param  array<string, mixed>  $repository  api.Repository
     * @return array{owner: ?string, name: ?string, full_name: ?string, html_url: ?string, clone_url: ?string, ssh_url: ?string, default_branch: ?string}
     */
    public static function repository(array $repository): array
    {
        return [
            'owner' => self::string(self::map($repository, 'owner'), 'login'),
            'name' => self::string($repository, 'name'),
            'full_name' => self::string($repository, 'full_name'),
            'html_url' => self::string($repository, 'html_url'),
            'clone_url' => self::string($repository, 'clone_url'),
            'ssh_url' => self::string($repository, 'ssh_url'),
            'default_branch' => self::string($repository, 'default_branch'),
        ];
    }

    /**
     * The head branch is `head.label`: `head.ref` turns into
     * `refs/pull/{n}/head` once the branch is deleted (services/convert/pull.go).
     *
     * @param  array<string, mixed>  $pr  api.PullRequest
     * @return array{number: int, title: string, body: ?string, state: string, url: string, head_branch: string, head_sha?: ?string, author_username: ?string, opened_at: string, merged_at: ?string, closed_at: ?string, updated_at: ?string}
     */
    public static function pullRequest(array $pr): array
    {
        $head = self::map($pr, 'head');

        return [
            'number' => (int) ($pr['number'] ?? 0),
            'title' => (string) ($pr['title'] ?? ''),
            'body' => self::string($pr, 'body'),
            'state' => match (true) {
                ($pr['merged'] ?? false) === true => GitPullRequest::STATE_MERGED,
                ($pr['state'] ?? null) === 'closed' => GitPullRequest::STATE_CLOSED,
                default => GitPullRequest::STATE_OPEN,
            },
            'url' => (string) self::string($pr, 'html_url'),
            'head_branch' => self::string($head, 'label') ?? (string) self::string($head, 'ref'),
            'head_sha' => self::string($head, 'sha'),
            'author_username' => self::string(self::map($pr, 'user'), 'login'),
            'opened_at' => (string) self::string($pr, 'created_at'),
            'merged_at' => self::string($pr, 'merged_at'),
            'closed_at' => self::string($pr, 'closed_at'),
            'updated_at' => self::string($pr, 'updated_at'),
        ];
    }

    /**
     * When an api.Commit reached its branch: its committer date, or its
     * author date when it has none.
     *
     * @param  array<string, mixed>  $commit  api.Commit
     */
    public static function commitDate(array $commit): ?string
    {
        $git = self::map($commit, 'commit');

        return self::string(self::map($git, 'committer'), 'date') ?? self::string(self::map($git, 'author'), 'date');
    }

    /**
     * A nested object, or an empty array.
     *
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    public static function map(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        return is_array($value) ? $value : [];
    }

    /**
     * A non-empty string field, or null.
     *
     * @param  array<mixed>  $data
     */
    public static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
