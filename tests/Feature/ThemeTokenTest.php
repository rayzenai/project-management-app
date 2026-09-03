<?php

it('defines every required token + mode for every theme', function () {
    $themes = config('themes.themes');
    $colorKeys = ['bg', 'surface', 'surfaceAlt', 'line', 'lineSoft', 'text', 'textMuted', 'textFaint', 'accent', 'accentDim', 'warn', 'danger', 'success'];
    $fontKeys = ['display', 'body', 'mono'];

    expect($themes)->toHaveKey('terminal-noir');

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
