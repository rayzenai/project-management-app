/**
 * Applies a server-resolved theme to the document by writing its token values
 * onto the semantic `--ws-*` CSS custom properties. The workspace stylesheet
 * (`workspace.css`) maps every Tailwind color utility name to one of these
 * vars via `@theme { --color-X: var(--ws-X) }`, so writing `--ws-*` here
 * rethemes the whole app at runtime without touching component markup.
 */

type Tokens = {
    color: Record<string, string>;
    font: { display: string; body: string; mono: string };
};

/** token key → semantic CSS variable the stylesheet consumes */
const WS_VAR: Record<string, string> = {
    bg: '--ws-bg',
    surface: '--ws-surface',
    surfaceAlt: '--ws-surface-alt',
    line: '--ws-line',
    lineSoft: '--ws-line-soft',
    text: '--ws-text',
    textMuted: '--ws-text-muted',
    textFaint: '--ws-text-faint',
    accent: '--ws-accent',
    accentDim: '--ws-accent-dim',
    warn: '--ws-warn',
    danger: '--ws-danger',
    success: '--ws-success',
};

export function applyTheme(tokens: Tokens, mode: 'light' | 'dark'): void {
    const root = document.documentElement;

    for (const [key, value] of Object.entries(tokens.color)) {
        const cssVar = WS_VAR[key];

        if (cssVar) {
            root.style.setProperty(cssVar, value);
        }
    }

    root.style.setProperty('--font-display', tokens.font.display);
    root.style.setProperty('--font-sans', tokens.font.body);
    root.style.setProperty('--font-mono', tokens.font.mono);

    root.style.colorScheme = mode;
    root.dataset.theme = mode;
    // Compatibility shim: un-migrated components still rely on the `.dark`
    // remap in workspace.css. Removed once the component sweep lands.
    root.classList.toggle('dark', mode === 'dark');
}

type SystemTokens = { light: Tokens; dark: Tokens };

export type Appearance = {
    theme: string;
    mode: 'light' | 'dark' | null;
    tokens: Tokens | SystemTokens;
};

/**
 * Module-level singleton for the OS scheme listener. Re-applying appearance on
 * every Inertia navigation must not accumulate listeners, so we remove the
 * previous one before attaching a new one (or clear it for a concrete theme).
 */
let systemListener: ((event: MediaQueryListEvent) => void) | null = null;

/**
 * Applies the shared `appearance` prop. For `system` the OS scheme decides the
 * mode (and a single listener re-applies on OS scheme changes); for a concrete
 * theme the server-provided `mode` is authoritative.
 */
export function applyAppearance(
    appearance: Appearance | null | undefined,
): void {
    if (!appearance) {
        return;
    }

    const mq = window.matchMedia('(prefers-color-scheme: dark)');

    if (systemListener) {
        mq.removeEventListener('change', systemListener);
        systemListener = null;
    }

    if (appearance.theme === 'system') {
        const sys = appearance.tokens as SystemTokens;
        const pick = (dark: boolean): void =>
            applyTheme(dark ? sys.dark : sys.light, dark ? 'dark' : 'light');

        pick(mq.matches);
        systemListener = (event: MediaQueryListEvent): void =>
            pick(event.matches);
        mq.addEventListener('change', systemListener);

        return;
    }

    applyTheme(appearance.tokens as Tokens, appearance.mode ?? 'dark');
}
