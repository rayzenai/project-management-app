<?php

it('defines every required token + mode for every theme', function () {
    $themes = config('themes.themes');
    $colorKeys = ['bg', 'surface', 'surfaceAlt', 'line', 'lineSoft', 'text', 'textMuted', 'textFaint', 'accent', 'accentDim', 'warn', 'danger', 'success'];
    $fontKeys = ['display', 'body', 'mono'];

    expect($themes)->toHaveKeys(['system', 'light', 'dark']);

    foreach ($themes as $key => $theme) {
        if ($key === 'system') {
            continue; // system resolves to light/dark, has no palette of its own
        }
        expect($theme)->toHaveKeys(['label', 'mode']);
        expect($theme['mode'])->toBeIn(['light', 'dark']);
        foreach ($colorKeys as $c) {
            expect($theme['tokens']['color'])->toHaveKey($c);
        }
        foreach ($fontKeys as $f) {
            expect($theme['tokens']['font'])->toHaveKey($f);
        }
    }
});

it('exposes a font allow-list and a default theme', function () {
    expect(config('themes.default'))->toBe('system')
        ->and(config('themes.font_allow_list'))->toHaveKeys(['display', 'body', 'mono']);
});

it('keeps the muted and faint text of app.css in step with the themes', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    // The first-paint light values (`:root`) and the dark ones under
    // `prefers-color-scheme: dark`, as the browser reads them before
    // applyTheme writes the theme's own.
    $block = function (string $selector) use ($css): string {
        $start = strpos($css, $selector.' {');
        expect($start)->not->toBeFalse();

        return substr($css, $start, strpos($css, '}', $start) - $start);
    };
    $value = function (string $block, string $var): ?string {
        return preg_match('/'.preg_quote($var, '/').':\s*(#[0-9a-fA-F]{6})\s*;/', $block, $m) === 1
            ? strtolower($m[1])
            : null;
    };

    $light = $block(':root');
    $dark = $block(":root:not([data-theme='light'])");

    foreach (['light' => $light, 'dark' => $dark] as $theme => $root) {
        $tokens = config("themes.themes.$theme.tokens.color");

        expect($value($root, '--ws-text-muted'))->toBe(strtolower($tokens['textMuted']))
            ->and($value($root, '--ws-text-faint'))->toBe(strtolower($tokens['textFaint']));
    }
});
