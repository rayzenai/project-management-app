// Shared open/close state for the command palette. The palette is rendered
// once in AppShell; the global `/` and ⌘K shortcuts and any search trigger
// button open it through this store. Query/results/activeIndex live inside
// CommandPalette.svelte (component-local, reset on close) — the store only
// carries cross-component intent, same division as notesBoard.

let isOpen = $state(false);
let initialQuery = $state('');

export const palette = {
    get isOpen(): boolean {
        return isOpen;
    },
    get initialQuery(): string {
        return initialQuery;
    },
    open(query = ''): void {
        initialQuery = query;
        isOpen = true;
    },
    close(): void {
        isOpen = false;
        initialQuery = '';
    },
};
