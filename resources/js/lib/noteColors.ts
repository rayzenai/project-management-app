import type { WorkspaceNoteColor } from './types';

/**
 * The sticky-note paper tints. This is the ONE place data-driven paper
 * colours (and `dark:` variants) are allowed: the semantic `--ws-*` tokens
 * carry no paper hues, so each tint is a pastel / desaturated hex pair that
 * keeps `text-fg` readable on both themes.
 */
export const NOTE_COLORS: WorkspaceNoteColor[] = [
    'amber',
    'rose',
    'sky',
    'emerald',
    'violet',
];

export const paperClass: Record<WorkspaceNoteColor, string> = {
    amber: 'bg-[#faf3dc] border-[#ecdfae] dark:bg-[#33301f] dark:border-[#4d4729]',
    rose: 'bg-[#fbe7ea] border-[#efc3ca] dark:bg-[#372529] dark:border-[#55353b]',
    sky: 'bg-[#e3eefb] border-[#bcd3f0] dark:bg-[#1f2b3d] dark:border-[#2e4160]',
    emerald:
        'bg-[#e2f3ea] border-[#b8dfc9] dark:bg-[#1e3128] dark:border-[#2d4a3b]',
    violet: 'bg-[#ece6fa] border-[#cfc2ef] dark:bg-[#2b2640] dark:border-[#423a60]',
};

/** Colour-picker swatches: one mid-tone per tint, identical on both themes. */
export const swatchClass: Record<WorkspaceNoteColor, string> = {
    amber: 'bg-[#e2b94a]',
    rose: 'bg-[#e07a8a]',
    sky: 'bg-[#5c93df]',
    emerald: 'bg-[#4bb583]',
    violet: 'bg-[#9a7fe0]',
};

/** Deterministic gentle tilt (deg) per note id, so the board looks hand-pinned. */
export function tilt(id: number): number {
    return ((id * 37) % 5) - 2;
}

/**
 * Map a task-note type to a sticky color, so task-anchored notes share the
 * freeform stickies' visual language while still reading by kind at a glance.
 */
export function noteTypeColor(type: string): WorkspaceNoteColor {
    switch (type) {
        case 'blocker':
            return 'rose';
        case 'general':
            return 'sky';
        case 'action_taken':
            return 'emerald';
        case 'meeting':
            return 'violet';
        case 'milestone':
        case 'decision':
        default:
            return 'amber';
    }
}
