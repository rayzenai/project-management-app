// Shared open/close state for the Task Peek slide-over. The panel itself is
// rendered once in AppShell; any task row, board card, or palette result opens
// it through this store. The current task's slug is mirrored into the
// `?task=` query param so peek links survive refresh and are shareable.

import type { Id } from './types';

export interface PeekTarget {
    id: Id;
    slug: string;
}

let target = $state<PeekTarget | null>(null);
let opener: HTMLElement | null = null;

function syncUrl(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    if (target) {
        url.searchParams.set('task', target.slug);
    } else {
        url.searchParams.delete('task');
    }

    // history.state must be passed through — Inertia keeps its page object there.
    window.history.replaceState(window.history.state, '', url);
}

export const peek = {
    get target(): PeekTarget | null {
        return target;
    },
    open(next: PeekTarget): void {
        opener =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        target = next;
        syncUrl();
    },
    close(): void {
        target = null;
        syncUrl();
        opener?.focus();
        opener = null;
    },
    syncUrl,
    /** Pages call this once on mount with the {id, slug} pairs they render. */
    openFromUrl(tasks: PeekTarget[]): void {
        if (typeof window === 'undefined' || target) {
            return;
        }

        const match = /[?&]task=([^&]+)/.exec(window.location.search);
        const slug = match ? decodeURIComponent(match[1]) : null;

        if (!slug) {
            return;
        }

        const found = tasks.find((t) => t.slug === slug);

        if (found) {
            target = { id: found.id, slug: found.slug };
        }
    },
};
