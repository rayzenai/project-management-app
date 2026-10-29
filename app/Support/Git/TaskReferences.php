<?php

namespace App\Support\Git;

/**
 * Task references (TP-12) in what the forge sends. Only the shape is checked
 * here; GitIngest resolves the keys within the repository's organization and
 * ignores the unknown ones.
 *
 * - Branch names match in any case (`tp-12-fix-login`).
 * - Commit messages and pull-request titles and bodies match in upper case
 *   only, skipping fenced code, inline code and URLs, so lower-case prose
 *   ("utf-8"), quoted code and pasted links never link a task.
 */
final class TaskReferences
{
    private const BRANCH = '/(?<![A-Za-z0-9])([A-Za-z]{1,10})-([1-9][0-9]{0,8})(?![0-9])/';

    private const TEXT = '/(?<![A-Za-z0-9])([A-Z]{1,10})-([1-9][0-9]{0,8})(?![0-9])/';

    /**
     * @return list<string> upper-case `KEY-N`, each once, in order
     */
    public static function inBranch(string $name): array
    {
        return self::matches(self::BRANCH, $name);
    }

    /**
     * @return list<string> `KEY-N`, each once, in order
     */
    public static function inText(string $text): array
    {
        // Each removal leaves a space, so the text around it cannot join up
        // into a new reference. An unclosed fence removes nothing after it.
        $text = (string) preg_replace(['/```.*?```/s', '/`[^`]*`/', '/\bhttps?:\/\/\S+/'], ' ', $text);

        return self::matches(self::TEXT, $text);
    }

    /**
     * @return list<string>
     */
    private static function matches(string $pattern, string $subject): array
    {
        preg_match_all($pattern, $subject, $found, PREG_SET_ORDER);

        $refs = array_map(fn (array $m): string => strtoupper($m[1]).'-'.$m[2], $found);

        return array_values(array_unique($refs));
    }
}
