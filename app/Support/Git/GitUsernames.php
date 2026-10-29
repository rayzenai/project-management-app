<?php

namespace App\Support\Git;

use App\Models\GitConnection;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person's forge username (`preferred_username`): made from their email
 * once, stored in users.git_username and never recomputed, so changing an
 * email never renames anyone on the forge. Every name is one Forgejo 15
 * accepts: ASCII letters, digits, `-`, `_` and `.`; a letter or digit at
 * both ends; no two separators in a row; at most 40 characters; not a name
 * or ending the forge keeps for itself, nor one of our own forge accounts.
 */
class GitUsernames
{
    public const MAX_LENGTH = 40;

    /** The forge's break-glass site admin (docs/forgejo.md). */
    public const FORGE_ADMIN = 'forge-admin';

    /** Names Forgejo reserves; treated as taken. */
    private const RESERVED = [
        'admin', 'api', 'assets', 'attachments', 'avatar', 'avatars', 'captcha',
        'commit', 'debug', 'error', 'explore', 'favicon.ico', 'ghost', 'issues',
        'login', 'manifest.json', 'metrics', 'milestones', 'new', 'notifications',
        'org', 'pulls', 'raw', 'repo', 'repo-avatars', 'robots.txt', 'search',
        'serviceworker.js', 'ssh_info', 'swagger.v1.json', 'user', 'v2',
        'gitea-actions', 'forgejo-actions',
    ];

    /** Endings Forgejo reserves; treated as taken. */
    private const RESERVED_ENDINGS = ['.keys', '.gpg', '.rss', '.atom', '.png'];

    public static function for(User $user): string
    {
        if ($user->git_username !== null) {
            return $user->git_username;
        }

        try {
            return self::assign($user);
        } catch (UniqueConstraintViolationException) {
            // Someone took the same name at the same moment: pick again, once.
            return self::assign($user);
        }
    }

    private static function assign(User $user): string
    {
        $base = self::base($user);
        $candidate = $base;
        $n = 2;

        while (self::isTaken($candidate, $user)) {
            $suffix = '-'.$n++;
            $candidate = self::clean(substr($base, 0, self::MAX_LENGTH - strlen($suffix))).$suffix;
        }

        // A savepoint, so a lost race leaves any surrounding transaction usable for the retry.
        DB::transaction(fn () => $user->forceFill(['git_username' => $candidate])->save());

        return $candidate;
    }

    /**
     * The email's local part, lowercased, anything outside [a-z0-9._-] made
     * a dash, separator runs made one dash, no separator at either end, at
     * most 40 characters; `user-{id}` when nothing is left.
     */
    private static function base(User $user): string
    {
        $name = preg_replace('/[^a-z0-9._-]/u', '-', mb_strtolower(Str::before($user->email, '@'))) ?? '';
        $name = self::clean(substr(self::clean($name), 0, self::MAX_LENGTH));

        return $name === '' ? 'user-'.$user->id : $name;
    }

    /**
     * Every run of two or more of `.`, `_` and `-` becomes one `-`, then the
     * ends are trimmed of them.
     */
    private static function clean(string $name): string
    {
        return trim(preg_replace('/[._-]{2,}/', '-', $name) ?? '', '._-');
    }

    private static function isTaken(string $name, User $user): bool
    {
        return in_array($name, self::RESERVED, true)
            || Str::endsWith($name, self::RESERVED_ENDINGS)
            || self::isForgeAccount($name)
            || User::query()->where('git_username', $name)->whereKeyNot($user->getKey())->exists();
    }

    /**
     * Forge users and organizations share one namespace, so every connected
     * forge organization, its `{owner}-bot` and the break-glass admin are
     * taken. Compared lowercased, as the forge does.
     */
    private static function isForgeAccount(string $name): bool
    {
        if ($name === self::FORGE_ADMIN) {
            return true;
        }

        foreach (GitConnection::query()->pluck('owner') as $owner) {
            $owner = mb_strtolower((string) $owner);

            if ($name === $owner || $name === $owner.'-bot') {
                return true;
            }
        }

        return false;
    }
}
