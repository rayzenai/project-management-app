import type { WorkspaceNoteColor } from './types';

export const NOTE_COLORS: WorkspaceNoteColor[] = [
    'amber',
    'rose',
    'sky',
    'emerald',
    'violet',
];

export const paperClass: Record<WorkspaceNoteColor, string> = {
    amber: 'bg-amber-200/95 border-amber-300 dark:bg-amber-400/20 dark:border-amber-400/30',
    rose: 'bg-rose-200/95 border-rose-300 dark:bg-rose-400/20 dark:border-rose-400/30',
    sky: 'bg-sky-200/95 border-sky-300 dark:bg-sky-400/20 dark:border-sky-400/30',
    emerald:
        'bg-emerald-200/95 border-emerald-300 dark:bg-emerald-400/20 dark:border-emerald-400/30',
    violet: 'bg-violet-200/95 border-violet-300 dark:bg-violet-400/20 dark:border-violet-400/30',
};

export const swatchClass: Record<WorkspaceNoteColor, string> = {
    amber: 'bg-amber-400',
    rose: 'bg-rose-400',
    sky: 'bg-sky-400',
    emerald: 'bg-emerald-400',
    violet: 'bg-violet-400',
};

/** Deterministic gentle tilt (deg) per note id, so the board looks hand-pinned. */
export function tilt(id: number): number {
    return ((id * 37) % 11) - 5;
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
