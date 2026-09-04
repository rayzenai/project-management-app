<?php

/*
|--------------------------------------------------------------------------
| Appearance
|--------------------------------------------------------------------------
|
| One visual language, two grounds. `system` follows the OS scheme and
| resolves to the `light` / `dark` token sets below. The UI itself is
| neutral (cool-grey surfaces, three text tones, hairline borders); one calm
| blue carries buttons, active navigation and progress, and green / amber /
| red are reserved for status and deadlines.
|
| Fonts: Geist for the UI, Geist Mono for ids / dates / counts, Mukta for
| Nepali (Devanagari) titles. The font allow-list exists for the preference
| API's validation; there is one sanctioned face per role.
|
*/

$fonts = [
    'display' => 'Geist, ui-sans-serif, system-ui, sans-serif',
    'body' => 'Geist, ui-sans-serif, system-ui, sans-serif',
    'mono' => 'Geist Mono, ui-monospace, Menlo, monospace',
];

return [
    'default' => 'system',

    'font_allow_list' => [
        'display' => ['Geist'],
        'body' => ['Geist'],
        'mono' => ['Geist Mono'],
    ],

    'themes' => [
        'system' => ['label' => 'System', 'mode' => null],

        'light' => [
            'label' => 'Light',
            'mode' => 'light',
            'tokens' => [
                'color' => [
                    'bg' => '#f2f4f8', 'surface' => '#ffffff', 'surfaceAlt' => '#f7f8fb',
                    'hover' => '#f0f2f7', 'raised' => '#ffffff',
                    'line' => '#e2e5ec', 'lineSoft' => '#ebedf3',
                    'text' => '#1b2030', 'textMuted' => '#626b7d', 'textFaint' => '#9aa2b3',
                    'accent' => '#3b6fe0', 'accentDim' => '#2f5fc7', 'accentSoft' => 'rgba(59,111,224,0.10)',
                    'warn' => '#b8760a', 'danger' => '#d63d33', 'success' => '#1f9d62',
                    'warnSoft' => 'rgba(184,118,10,0.10)', 'dangerSoft' => 'rgba(214,61,51,0.09)', 'successSoft' => 'rgba(31,157,98,0.10)',
                    'selection' => 'rgba(59,111,224,0.07)',
                ],
                'font' => $fonts,
            ],
        ],

        'dark' => [
            'label' => 'Dark',
            'mode' => 'dark',
            'tokens' => [
                'color' => [
                    'bg' => '#0e1015', 'surface' => '#151820', 'surfaceAlt' => '#1a1e27',
                    'hover' => '#1f2430', 'raised' => '#242a37',
                    'line' => '#262c39', 'lineSoft' => '#1f2430',
                    'text' => '#e4e7ee', 'textMuted' => '#8e95a6', 'textFaint' => '#5b6275',
                    'accent' => '#6d94f5', 'accentDim' => '#5a82e6', 'accentSoft' => 'rgba(109,148,245,0.14)',
                    'warn' => '#e5a33a', 'danger' => '#f2655a', 'success' => '#3ec98a',
                    'warnSoft' => 'rgba(229,163,58,0.13)', 'dangerSoft' => 'rgba(242,101,90,0.13)', 'successSoft' => 'rgba(62,201,138,0.13)',
                    'selection' => 'rgba(109,148,245,0.08)',
                ],
                'font' => $fonts,
            ],
        ],
    ],
];
