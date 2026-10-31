<?php

namespace App\Support\Git;

/**
 * A commit's checks (the forge's commit statuses, which Forgejo Actions
 * writes for each job) in the tracker's words: each check `passed`,
 * `failed` or `running`, and the commit overall `failed` if any failed,
 * else `running` if any runs, else `passed`, or `none` when nothing checks
 * it. Only `success` passes: the forge merges a pull request into a
 * protected `main` only then, so `error`, `failure` and `warning` fail.
 */
final class Checks
{
    public const PASSED = 'passed';

    public const FAILED = 'failed';

    public const RUNNING = 'running';

    public const NONE = 'none';

    /**
     * A check's link is kept only when it is on the forge itself: anyone who
     * can write to a repository can post a status with any target URL.
     *
     * @param  array<string, mixed>  $combined  api.CombinedStatus
     * @param  string  $forgeUrl  the connection's base URL
     * @return array{state: string, checks: list<array{name: string, state: string, url: string|null}>}
     */
    public static function fromCombined(array $combined, string $forgeUrl): array
    {
        $forgeHost = strtolower((string) parse_url($forgeUrl, PHP_URL_HOST));
        $checks = [];

        foreach ((array) ($combined['statuses'] ?? []) as $status) {
            $status = (array) $status;
            $url = (string) ($status['target_url'] ?? '');
            $onForge = in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['https', 'http'], true)
                && strtolower((string) parse_url($url, PHP_URL_HOST)) === $forgeHost;

            $checks[] = [
                'name' => (string) ($status['context'] ?? ''),
                'state' => match ((string) ($status['status'] ?? '')) {
                    'success' => self::PASSED,
                    'pending' => self::RUNNING,
                    default => self::FAILED,
                },
                'url' => $onForge ? $url : null,
            ];
        }

        usort($checks, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return ['state' => self::overall($checks), 'checks' => $checks];
    }

    /**
     * @param  list<array{state: string}>  $checks
     */
    public static function overall(array $checks): string
    {
        $states = array_column($checks, 'state');

        return match (true) {
            $states === [] => self::NONE,
            in_array(self::FAILED, $states, true) => self::FAILED,
            in_array(self::RUNNING, $states, true) => self::RUNNING,
            default => self::PASSED,
        };
    }

    /**
     * How a commit or pull request carries its checks to the pages: null
     * until the tracker has asked the forge.
     *
     * @param  list<array{name: string, state: string, url: string|null}>|null  $checks
     * @return array{state: string, checks: list<array{name: string, state: string, url: string|null}>}|null
     */
    public static function shape(?string $state, ?array $checks): ?array
    {
        return $state === null ? null : ['state' => $state, 'checks' => $checks ?? []];
    }
}
