<?php

namespace App\Support\Git;

/**
 * Files nobody wrote by hand (lock files, dependencies, build output,
 * minified files, source maps), so their lines never count as code.
 */
final class GeneratedFiles
{
    private const LOCK_FILES = [
        'package-lock.json', 'yarn.lock', 'pnpm-lock.yaml', 'composer.lock',
        'Gemfile.lock', 'Cargo.lock', 'poetry.lock', 'go.sum', 'pubspec.lock',
        'Podfile.lock', 'mix.lock',
    ];

    /** Directory names that mark generated content anywhere in a path. */
    private const SEGMENTS = ['vendor', 'node_modules', 'dist', 'build', '.next', 'coverage'];

    private const SUFFIXES = ['.min.js', '.min.css', '.map'];

    public static function matches(string $path): bool
    {
        $segments = explode('/', $path);
        $basename = array_pop($segments);

        if (in_array($basename, self::LOCK_FILES, true)) {
            return true;
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($basename, $suffix)) {
                return true;
            }
        }

        // `public/build/` is covered by the `build` segment.
        return array_intersect($segments, self::SEGMENTS) !== [];
    }
}
