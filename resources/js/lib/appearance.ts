import type { Appearance } from './applyTheme';

/** Token bundle as stored in `config/themes.php` and emitted by the API. */
export type ThemeTokens = {
    color: Record<string, string>;
    font: { display: string; body: string; mono: string };
};

/** A theme definition from `GET /api/v1/themes` (and the config). */
export type ConfigTheme = {
    key: string;
    label: string;
    mode: 'light' | 'dark' | null;
    /** Absent for the `system` theme; present for every concrete theme. */
    tokens?: ThemeTokens;
};

/** Font choices allowed by `font_allow_list`, keyed by role. */
export type FontAllowList = {
    display: string[];
    body: string[];
    mono: string[];
};

/** Per-role font override; `null` means "use the theme default". */
export type FontOverride = {
    display: string | null;
    body: string | null;
    mono: string | null;
};

/** The shared `appearance` Inertia prop. */
export type AppearanceProps = {
    theme: string;
    mode: 'light' | 'dark' | null;
    tokens: ThemeTokens | { light: ThemeTokens; dark: ThemeTokens };
    font_override: Partial<FontOverride> | null;
    email_notifications: boolean;
    configured: boolean;
};

/** Display order for the theme grid (System first). */
export const THEME_ORDER = [
    'system',
    'terminal-noir',
    'light',
    'dark',
    'paper',
    'glass',
];

/** Turns the `themes` map from `GET /api/v1/themes` into an ordered array. */
export function themesToList(
    themes: Record<
        string,
        { label: string; mode: 'light' | 'dark' | null; tokens?: ThemeTokens }
    >,
): ConfigTheme[] {
    const keys = Object.keys(themes);
    const ordered = [
        ...THEME_ORDER.filter((k) => k in themes),
        ...keys.filter((k) => !THEME_ORDER.includes(k)),
    ];

    return ordered.map((key) => ({
        key,
        label: themes[key].label,
        mode: themes[key].mode,
        tokens: themes[key].tokens,
    }));
}

/** Applies a per-role font override onto a token set (mutates a clone). */
function withFonts(tokens: ThemeTokens, override: FontOverride): ThemeTokens {
    return {
        color: tokens.color,
        font: {
            display: override.display ?? tokens.font.display,
            body: override.body ?? tokens.font.body,
            mono: override.mono ?? tokens.font.mono,
        },
    };
}

/**
 * Builds the `Appearance` payload `applyAppearance` expects from a selected
 * config theme + the chosen font override, for LIVE preview before saving.
 * For `system` it resolves both light + dark sets from the supplied
 * `{light,dark}` token pair so the OS scheme decides the mode.
 */
export function previewAppearance(
    theme: ConfigTheme,
    override: FontOverride,
    systemTokens?: { light: ThemeTokens; dark: ThemeTokens },
): Appearance {
    if (theme.key === 'system' && systemTokens) {
        return {
            theme: 'system',
            mode: null,
            tokens: {
                light: withFonts(systemTokens.light, override),
                dark: withFonts(systemTokens.dark, override),
            },
        };
    }

    return {
        theme: theme.key,
        mode: theme.mode ?? 'dark',
        tokens: withFonts(theme.tokens!, override),
    };
}
