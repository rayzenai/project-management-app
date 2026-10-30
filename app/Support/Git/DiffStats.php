<?php

namespace App\Support\Git;

/**
 * Turns one commit's unified diff (Forgejo's `/git/commits/{sha}.diff`) into
 * line counts: code, comments, blank, docs and generated. Linear in the size
 * of the diff, one line at a time.
 *
 * Comment detection is a heuristic like `cloc`: a line is a comment when,
 * after its indentation, it starts with a line marker or sits inside, opens
 * or closes a block comment. Code before a block opener makes it code.
 * Block state is tracked per side through each hunk and resets at every
 * hunk and file.
 */
final class DiffStats
{
    public const DOC_EXTENSIONS = ['md', 'markdown', 'rst', 'txt', 'adoc'];

    /**
     * @return array{files_changed: int, code_added: int, code_removed: int, comment_added: int, comment_removed: int, blank_added: int, blank_removed: int, docs_added: int, docs_removed: int, generated_lines: int}
     */
    public static function classify(string $diff): array
    {
        $stats = [
            'files_changed' => 0, 'code_added' => 0, 'code_removed' => 0,
            'comment_added' => 0, 'comment_removed' => 0, 'blank_added' => 0,
            'blank_removed' => 0, 'docs_added' => 0, 'docs_removed' => 0,
            'generated_lines' => 0,
        ];

        $path = '';
        $inHunk = false;
        $kind = 'code';
        $syntax = null;
        $oldBlock = null;
        $newBlock = null;

        foreach (explode("\n", $diff) as $raw) {
            $line = rtrim($raw, "\r");

            if (! $inHunk || str_starts_with($line, 'diff --git ')) {
                if (str_starts_with($line, 'diff --git ')) {
                    $stats['files_changed']++;
                    $inHunk = false;
                    $path = self::pathFromHeader($line);
                } elseif (str_starts_with($line, '+++ ') && $line !== '+++ /dev/null') {
                    $path = self::stripPrefix(substr($line, 4), 'b/');
                } elseif (str_starts_with($line, '--- ') && $line !== '--- /dev/null') {
                    // The old path, used only when the new side is /dev/null.
                    $path = self::stripPrefix(substr($line, 4), 'a/');
                } elseif (str_starts_with($line, 'rename to ')) {
                    $path = substr($line, 10);
                } elseif (str_starts_with($line, '@@')) {
                    $inHunk = true;
                    $oldBlock = $newBlock = null;
                    $kind = match (true) {
                        GeneratedFiles::matches($path) => 'generated',
                        in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::DOC_EXTENSIONS, true) => 'docs',
                        default => 'code',
                    };
                    $syntax = $kind === 'code' ? CommentSyntax::forPath($path) : null;
                }

                continue;
            }

            if (str_starts_with($line, '@@')) {
                $oldBlock = $newBlock = null;

                continue;
            }

            $marker = $line === '' ? ' ' : $line[0];
            $text = (string) substr($line, 1);

            if ($marker === '\\') {
                continue;
            }

            if ($marker !== '+' && $marker !== '-' && $marker !== ' ') {
                continue;
            }

            if ($kind === 'generated') {
                if ($marker !== ' ') {
                    $stats['generated_lines']++;
                }

                continue;
            }

            if ($kind === 'docs') {
                if ($marker !== ' ') {
                    $stats[$marker === '+' ? 'docs_added' : 'docs_removed']++;
                }

                continue;
            }

            $isBlank = trim($text) === '';
            $isCode = false;

            if ($marker === '-') {
                [$isCode, $oldBlock] = self::scan($text, $syntax, $oldBlock);
            } elseif ($marker === '+') {
                [$isCode, $newBlock] = self::scan($text, $syntax, $newBlock);
            } else {
                [, $oldBlock] = self::scan($text, $syntax, $oldBlock);
                [, $newBlock] = self::scan($text, $syntax, $newBlock);

                continue;
            }

            $side = $marker === '+' ? 'added' : 'removed';
            $bucket = $isBlank ? 'blank' : ($isCode ? 'code' : 'comment');
            $stats["{$bucket}_{$side}"]++;
        }

        return $stats;
    }

    /** The new path of `diff --git a/x b/y` (a first guess; the ---/+++ lines refine it). */
    private static function pathFromHeader(string $line): string
    {
        $rest = substr($line, 11);

        if (preg_match('#^a/(.+) b/(.+)$#', $rest, $m) === 1) {
            return $m[2];
        }

        return $rest;
    }

    private static function stripPrefix(string $path, string $prefix): string
    {
        // Git appends a tab to paths with spaces on the ---/+++ lines.
        $path = rtrim($path, "\t");

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /**
     * Reads one line of text on one side.
     *
     * @param  array{line: list<string>, block: list<array{0: string, 1: string}>}|null  $syntax
     * @param  string|null  $closer  the closing marker of the block we are inside
     * @return array{0: bool, 1: string|null} whether the line holds code, and the block state after it
     */
    private static function scan(string $text, ?array $syntax, ?string $closer): array
    {
        if ($syntax === null) {
            return [true, null];
        }

        $length = strlen($text);
        $pos = 0;
        $hasCode = false;

        while ($pos < $length) {
            if ($closer !== null) {
                $end = strpos($text, $closer, $pos);
                if ($end === false) {
                    return [$hasCode, $closer];
                }
                $pos = $end + strlen($closer);
                $closer = null;

                continue;
            }

            // Skip indentation.
            $pos += strspn($text, " \t", $pos);
            if ($pos >= $length) {
                break;
            }

            if ($hasCode) {
                // Code seen: the next marker decides what follows it.
                $next = self::nextMarker($text, $pos, $syntax);
                if ($next === null || $next[0] === 'line') {
                    break;
                }
                $pos = $next[1] + strlen($next[2]);
                $closer = $next[3];

                continue;
            }

            $at = self::markerAt($text, $pos, $syntax);
            if ($at === null) {
                $hasCode = true;

                continue;
            }
            if ($at[0] === 'line') {
                break;
            }
            $pos += strlen($at[2]);
            $closer = $at[3];
        }

        return [$hasCode, $closer];
    }

    /**
     * @param  array{line: list<string>, block: list<array{0: string, 1: string}>}  $syntax
     * @return array{0: string, 1: int, 2: string, 3: string|null}|null kind, offset, marker, closer
     */
    private static function markerAt(string $text, int $pos, array $syntax): ?array
    {
        foreach ($syntax['block'] as [$open, $close]) {
            if (substr_compare($text, $open, $pos, strlen($open)) === 0) {
                return ['block', $pos, $open, $close];
            }
        }
        foreach ($syntax['line'] as $marker) {
            if (substr_compare($text, $marker, $pos, strlen($marker)) === 0) {
                return ['line', $pos, $marker, null];
            }
        }

        return null;
    }

    /**
     * The earliest marker at or after $from; a block opener wins a tie.
     *
     * @param  array{line: list<string>, block: list<array{0: string, 1: string}>}  $syntax
     * @return array{0: string, 1: int, 2: string, 3: string|null}|null
     */
    private static function nextMarker(string $text, int $from, array $syntax): ?array
    {
        $best = null;

        foreach ($syntax['block'] as [$open, $close]) {
            $at = strpos($text, $open, $from);
            if ($at !== false && ($best === null || $at < $best[1])) {
                $best = ['block', $at, $open, $close];
            }
        }
        foreach ($syntax['line'] as $marker) {
            $at = strpos($text, $marker, $from);
            if ($at !== false && ($best === null || $at < $best[1])) {
                $best = ['line', $at, $marker, null];
            }
        }

        return $best;
    }
}
