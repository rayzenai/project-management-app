<?php

namespace App\Support\Git;

/**
 * Comment markers per file type, as data. A heuristic like `cloc`'s: it does
 * not know strings or heredocs.
 *
 * @phpstan-type Syntax array{line: list<string>, block: list<array{0: string, 1: string}>}
 */
final class CommentSyntax
{
    private const C_BLOCK = [['/*', '*/']];

    /** @var array<string, Syntax> */
    private const SYNTAXES = [
        'c' => ['line' => ['//'], 'block' => self::C_BLOCK],
        'css' => ['line' => [], 'block' => self::C_BLOCK],
        'hash' => ['line' => ['#'], 'block' => []],
        'python' => ['line' => ['#'], 'block' => [['"""', '"""'], ["'''", "'''"]]],
        'ini' => ['line' => [';', '#'], 'block' => []],
        'sql' => ['line' => ['--'], 'block' => self::C_BLOCK],
        'lua' => ['line' => ['--'], 'block' => [['--[[', ']]']]],
        'haskell' => ['line' => ['--'], 'block' => [['{-', '-}']]],
        'markup' => ['line' => [], 'block' => [['<!--', '-->']]],
        'component' => ['line' => ['//'], 'block' => [['/*', '*/'], ['<!--', '-->']]],
        'blade' => ['line' => ['//'], 'block' => [['{{--', '--}}'], ['<!--', '-->'], ['/*', '*/']]],
    ];

    /** @var array<string, string> extension or basename => key of SYNTAXES */
    private const TYPES = [
        'php' => 'c', 'js' => 'c', 'mjs' => 'c', 'cjs' => 'c', 'ts' => 'c', 'tsx' => 'c',
        'jsx' => 'c', 'java' => 'c', 'kt' => 'c', 'kts' => 'c', 'swift' => 'c', 'go' => 'c',
        'rs' => 'c', 'c' => 'c', 'h' => 'c', 'cc' => 'c', 'cpp' => 'c', 'hpp' => 'c',
        'cs' => 'c', 'dart' => 'c', 'scala' => 'c', 'groovy' => 'c', 'gradle' => 'c',
        'scss' => 'c', 'less' => 'c', 'json5' => 'c',
        'css' => 'css',
        'rb' => 'hash', 'sh' => 'hash', 'bash' => 'hash', 'zsh' => 'hash', 'yaml' => 'hash',
        'yml' => 'hash', 'toml' => 'hash', 'r' => 'hash', 'pl' => 'hash', 'ps1' => 'hash',
        'conf' => 'hash', 'env' => 'hash', 'Dockerfile' => 'hash', 'Makefile' => 'hash',
        'py' => 'python',
        'ini' => 'ini',
        'sql' => 'sql',
        'lua' => 'lua',
        'hs' => 'haskell',
        'html' => 'markup', 'htm' => 'markup', 'xml' => 'markup', 'svg' => 'markup',
        'vue' => 'component', 'svelte' => 'component',
    ];

    /**
     * @return Syntax|null null for a type we do not know: its lines are code
     */
    public static function forPath(string $path): ?array
    {
        $basename = basename($path);

        if (str_ends_with(strtolower($basename), '.blade.php')) {
            return self::SYNTAXES['blade'];
        }

        $type = self::TYPES[$basename] ?? self::TYPES[strtolower(pathinfo($basename, PATHINFO_EXTENSION))] ?? null;

        return $type === null ? null : self::SYNTAXES[$type];
    }
}
