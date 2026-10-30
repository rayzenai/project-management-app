<?php

use App\Support\Git\DiffStats;
use App\Support\Git\GeneratedFiles;

/**
 * A one-file, one-hunk diff of $path from $lines (each already prefixed with
 * ' ', '+' or '-').
 *
 * @param  list<string>  $lines
 */
function fileDiff(string $path, array $lines, string $from = 'a/', string $to = 'b/'): string
{
    $old = $from === '/dev/null' ? '/dev/null' : $from.$path;
    $new = $to === '/dev/null' ? '/dev/null' : $to.$path;

    return "diff --git a/{$path} b/{$path}\nindex 1111111..2222222 100644\n--- {$old}\n+++ {$new}\n@@ -1,3 +1,3 @@\n"
        .implode("\n", $lines)."\n";
}

/**
 * @param  array<string, int>  $set
 * @return array<string, int>
 */
function stats(array $set = []): array
{
    return array_merge([
        'files_changed' => 1, 'code_added' => 0, 'code_removed' => 0,
        'comment_added' => 0, 'comment_removed' => 0, 'blank_added' => 0,
        'blank_removed' => 0, 'docs_added' => 0, 'docs_removed' => 0,
        'generated_lines' => 0,
    ], $set);
}

it('counts code, line comments, a docblock and a blank line in php', function () {
    $diff = fileDiff('app/Foo.php', [
        '+$a = 1;',
        '+$b = 2;',
        '+// note',
        '+/**',
        '+ * Docs.',
        '+ */',
        '+',
    ]);

    expect(DiffStats::classify($diff))->toBe(stats(['code_added' => 2, 'comment_added' => 4, 'blank_added' => 1]));
});

it('treats code before a block opener as code', function () {
    $diff = fileDiff('a.php', ['+$x = 1; /* note */', '+$y = 2; /* open', '+still comment', '+*/']);

    expect(DiffStats::classify($diff))->toBe(stats(['code_added' => 2, 'comment_added' => 2]));
});

it('counts hash comments and a python docstring', function () {
    $diff = fileDiff('a.py', [
        '+# header',
        '+def f():',
        '+    """Doc',
        '+    more',
        '+    """',
        '+    return 1',
    ]);

    expect(DiffStats::classify($diff))->toBe(stats(['code_added' => 2, 'comment_added' => 4]));
});

it('counts a one-line python docstring as a comment', function () {
    expect(DiffStats::classify(fileDiff('a.py', ['+    """One line."""'])))->toBe(stats(['comment_added' => 1]));
});

it('carries a block opened on a context line into added lines', function () {
    $diff = fileDiff('a.js', [
        ' /* start',
        '+middle',
        '+end */',
        '+code();',
    ]);

    expect(DiffStats::classify($diff))->toBe(stats(['comment_added' => 2, 'code_added' => 1]));
});

it('tracks block state separately for the old and the new side', function () {
    $diff = fileDiff('a.js', [
        '-/* old',
        '-comment */',
        '+code();',
        '-old();',
        '+/* new',
        ' shared',
        '+end */',
    ]);

    // old: "/* old", "comment */" comment; "old();" is after the block closed: code.
    // new: "code();" code; "/* new" opens, " shared" (context) and "end */" comment.
    expect(DiffStats::classify($diff))->toBe(stats([
        'comment_removed' => 2, 'code_removed' => 1, 'code_added' => 1, 'comment_added' => 2,
    ]));
});

it('resets block state at each hunk', function () {
    $diff = "diff --git a/a.js b/a.js\n--- a/a.js\n+++ b/a.js\n@@ -1,1 +1,1 @@\n+/* never closed\n@@ -9,1 +9,1 @@\n+code();\n";

    expect(DiffStats::classify($diff))->toBe(stats(['comment_added' => 1, 'code_added' => 1]));
});

it('counts removed lines on the removed side only', function () {
    $diff = fileDiff('a.php', ['-$a = 1;', '-// gone', '-', ' $b = 2;']);

    expect(DiffStats::classify($diff))->toBe(stats(['code_removed' => 1, 'comment_removed' => 1, 'blank_removed' => 1]));
});

it('counts markdown as docs', function () {
    $diff = fileDiff('README.md', ['+# Title', '+', '-old']);

    expect(DiffStats::classify($diff))->toBe(stats(['docs_added' => 2, 'docs_removed' => 1]));
});

it('counts generated files only as generated lines', function (string $path) {
    $diff = fileDiff($path, ['+a', '+b', '-c']);

    expect(DiffStats::classify($diff))->toBe(stats(['generated_lines' => 3]));
})->with(['package-lock.json', 'vendor/foo/bar.php', 'public/js/app.min.js', 'web/app.js.map']);

it('matches the generated list', function () {
    foreach (['yarn.lock', 'sub/composer.lock', 'go.sum', 'a/node_modules/x/i.js', 'dist/a.js', 'public/build/a.js', '.next/a', 'coverage/lcov.info', 'a.min.css'] as $path) {
        expect(GeneratedFiles::matches($path))->toBeTrue($path);
    }
    foreach (['app/Foo.php', 'distance/a.php', 'src/build.ts', 'locks/a.txt', 'a.min.php'] as $path) {
        expect(GeneratedFiles::matches($path))->toBeFalse($path);
    }
});

it('counts a binary file as a file with no lines', function () {
    $diff = "diff --git a/logo.png b/logo.png\nindex 1..2 100644\nBinary files a/logo.png and b/logo.png differ\n";

    expect(DiffStats::classify($diff))->toBe(stats());
});

it('reads a new file and a deleted file', function () {
    expect(DiffStats::classify(fileDiff('a.php', ['+$a = 1;', '+// c'], '/dev/null')))
        ->toBe(stats(['code_added' => 1, 'comment_added' => 1]))
        ->and(DiffStats::classify(fileDiff('a.php', ['-$a = 1;', '-// c'], 'a/', '/dev/null')))
        ->toBe(stats(['code_removed' => 1, 'comment_removed' => 1]));
});

it('counts a pure rename as a file with no lines', function () {
    $diff = "diff --git a/old.php b/new.php\nsimilarity index 100%\nrename from old.php\nrename to new.php\n";

    expect(DiffStats::classify($diff))->toBe(stats());
});

it('classifies a deleted vendor file as generated', function () {
    $diff = fileDiff('vendor/x.php', ['-a'], 'a/', '/dev/null');

    expect(DiffStats::classify($diff))->toBe(stats(['generated_lines' => 1]));
});

it('recognises html, blade, sql and yaml comments', function () {
    expect(DiffStats::classify(fileDiff('a.html', ['+<!-- a', '+b -->', '+<p>x</p>'])))
        ->toBe(stats(['comment_added' => 2, 'code_added' => 1]))
        ->and(DiffStats::classify(fileDiff('resources/views/a.blade.php', ['+{{-- a', '+b --}}', '+{{ $x }}', '+// c'])))
        ->toBe(stats(['comment_added' => 3, 'code_added' => 1]))
        ->and(DiffStats::classify(fileDiff('a.sql', ['+-- c', '+select 1;'])))
        ->toBe(stats(['comment_added' => 1, 'code_added' => 1]))
        ->and(DiffStats::classify(fileDiff('a.yml', ['+# c', '+a: 1'])))
        ->toBe(stats(['comment_added' => 1, 'code_added' => 1]));
});

it('counts every line of an unknown extension as code', function () {
    expect(DiffStats::classify(fileDiff('a.xyz', ['+// not a comment', '+# nor this'])))->toBe(stats(['code_added' => 2]));
});

it('strips carriage returns and ignores the no-newline marker', function () {
    $diff = str_replace("\n", "\r\n", fileDiff('a.php', ['+$a = 1;', '+// c', '+', '\\ No newline at end of file']));

    expect(DiffStats::classify($diff))->toBe(stats(['code_added' => 1, 'comment_added' => 1, 'blank_added' => 1]));
});

it('is not fooled by a removed line that looks like a header', function () {
    $diff = fileDiff('a.sql', ['--- a comment', '+++ b']);

    expect(DiffStats::classify($diff))->toBe(stats(['comment_removed' => 1, 'code_added' => 1]));
});

it('sums two files', function () {
    $diff = fileDiff('a.php', ['+$a = 1;', '+// c']).fileDiff('README.md', ['+x']);

    expect(DiffStats::classify($diff))->toBe(stats(['files_changed' => 2, 'code_added' => 1, 'comment_added' => 1, 'docs_added' => 1]));
});

it('returns zeros for an empty diff', function () {
    expect(DiffStats::classify(''))->toBe(stats(['files_changed' => 0]));
});
