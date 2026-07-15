<?php

return [
    'default' => 'system',

    'font_allow_list' => [
        'display' => ['Syne', 'Inter Tight', 'Fraunces', 'Space Grotesk'],
        'body' => ['Hanken Grotesk', 'Inter', 'Newsreader'],
        'mono' => ['IBM Plex Mono', 'JetBrains Mono'],
    ],

    'themes' => [
        'system' => ['label' => 'System', 'mode' => null],

        'terminal-noir' => [
            'label' => 'Terminal Noir',
            'mode' => 'dark',
            'tokens' => [
                'color' => [
                    'bg' => '#08090a', 'surface' => '#111417', 'surfaceAlt' => '#161a1e',
                    'line' => '#20262b', 'lineSoft' => '#181d21',
                    'text' => '#e9e7e2', 'textMuted' => '#9aa1a8', 'textFaint' => '#5c646b',
                    'accent' => '#b6ff3a', 'accentDim' => '#8bc91f',
                    'warn' => '#f4b740', 'danger' => '#ff5c5c', 'success' => '#b6ff3a',
                ],
                'font' => [
                    'display' => 'Syne, ui-sans-serif, system-ui, sans-serif',
                    'body' => 'Hanken Grotesk, ui-sans-serif, system-ui, sans-serif',
                    'mono' => 'IBM Plex Mono, ui-monospace, monospace',
                ],
            ],
        ],

        'light' => [
            'label' => 'Light',
            'mode' => 'light',
            'tokens' => [
                'color' => [
                    'bg' => '#f7f7f5', 'surface' => '#ffffff', 'surfaceAlt' => '#f0f0ee',
                    'line' => '#e2e2dd', 'lineSoft' => '#ededea',
                    'text' => '#14151a', 'textMuted' => '#5c636e', 'textFaint' => '#9aa1ab',
                    'accent' => '#3b5bff', 'accentDim' => '#2f49cc',
                    'warn' => '#c77d00', 'danger' => '#d23b3b', 'success' => '#1e9e5a',
                ],
                'font' => [
                    'display' => 'Inter Tight, ui-sans-serif, system-ui, sans-serif',
                    'body' => 'Inter, ui-sans-serif, system-ui, sans-serif',
                    'mono' => 'JetBrains Mono, ui-monospace, monospace',
                ],
            ],
        ],

        'dark' => [
            'label' => 'Dark',
            'mode' => 'dark',
            'tokens' => [
                'color' => [
                    'bg' => '#0e0f12', 'surface' => '#16181d', 'surfaceAlt' => '#1c1f25',
                    'line' => '#2a2e36', 'lineSoft' => '#20242b',
                    'text' => '#e7e9ee', 'textMuted' => '#98a0ac', 'textFaint' => '#5f6772',
                    'accent' => '#6f8bff', 'accentDim' => '#5870e6',
                    'warn' => '#e0a23a', 'danger' => '#ff6b6b', 'success' => '#46c98a',
                ],
                'font' => [
                    'display' => 'Inter Tight, ui-sans-serif, system-ui, sans-serif',
                    'body' => 'Inter, ui-sans-serif, system-ui, sans-serif',
                    'mono' => 'JetBrains Mono, ui-monospace, monospace',
                ],
            ],
        ],

        'paper' => [
            'label' => 'Paper',
            'mode' => 'light',
            'tokens' => [
                'color' => [
                    'bg' => '#f4f1ea', 'surface' => '#fbf9f3', 'surfaceAlt' => '#efe9dc',
                    'line' => '#ddd5c5', 'lineSoft' => '#e8e1d3',
                    'text' => '#2a2620', 'textMuted' => '#6b6253', 'textFaint' => '#9c9384',
                    'accent' => '#b4541f', 'accentDim' => '#94431a',
                    'warn' => '#c7891f', 'danger' => '#c0402f', 'success' => '#5b7d2f',
                ],
                'font' => [
                    'display' => 'Fraunces, ui-serif, Georgia, serif',
                    'body' => 'Newsreader, ui-serif, Georgia, serif',
                    'mono' => 'IBM Plex Mono, ui-monospace, monospace',
                ],
            ],
        ],

        'glass' => [
            'label' => 'Glass',
            'mode' => 'dark',
            'tokens' => [
                'color' => [
                    'bg' => '#0c0e14', 'surface' => '#161a24', 'surfaceAlt' => '#1e2330',
                    'line' => '#2b3140', 'lineSoft' => '#1f2531',
                    'text' => '#e8ebf2', 'textMuted' => '#97a0b3', 'textFaint' => '#5d6678',
                    'accent' => '#9d8bff', 'accentDim' => '#7f6ce6',
                    'warn' => '#e6b04a', 'danger' => '#ff6f7d', 'success' => '#54cf9a',
                ],
                'font' => [
                    'display' => 'Space Grotesk, ui-sans-serif, system-ui, sans-serif',
                    'body' => 'Inter, ui-sans-serif, system-ui, sans-serif',
                    'mono' => 'JetBrains Mono, ui-monospace, monospace',
                ],
            ],
        ],
    ],
];
