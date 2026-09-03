// Shared open/close state for the sticky-notes board. The board itself is
// rendered once in AppShell; any page (e.g. the My Workspace header strip) can
// open it — optionally focused on a note or in compose mode — through this store.

let isOpen = $state(false);
let focusId = $state<number | null>(null);
let composeIntent = $state(false);

function show(options: { noteId?: number; compose?: boolean } = {}): void {
    focusId = options.noteId ?? null;
    composeIntent = options.compose ?? false;
    isOpen = true;
}

function hide(): void {
    isOpen = false;
    focusId = null;
    composeIntent = false;
}

export const notesBoard = {
    get open(): boolean {
        return isOpen;
    },
    get focusId(): number | null {
        return focusId;
    },
    get compose(): boolean {
        return composeIntent;
    },
    show,
    hide,
    toggle(): void {
        if (isOpen) {
            hide();
        } else {
            show();
        }
    },
};
