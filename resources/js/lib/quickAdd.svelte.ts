// Shared open/close state for the global quick-add overlay. The overlay is
// rendered once in AppShell; the global `q` shortcut, the palette's "New
// task…" action (which hands its query over as prefill), and project pages
// (which can pre-select their project) all open it through this store.

let isOpen = $state(false);
let prefill = $state('');
let projectId = $state<number | null>(null);
let lockProject = $state(false);

export const quickAdd = {
    get isOpen(): boolean {
        return isOpen;
    },
    get prefill(): string {
        return prefill;
    },
    get projectId(): number | null {
        return projectId;
    },
    get lockProject(): boolean {
        return lockProject;
    },
    open(
        options: {
            prefill?: string;
            projectId?: number;
            lockProject?: boolean;
        } = {},
    ): void {
        prefill = options.prefill ?? '';
        projectId = options.projectId ?? null;
        lockProject = options.lockProject ?? false;
        isOpen = true;
    },
    close(): void {
        isOpen = false;
        prefill = '';
        projectId = null;
        lockProject = false;
    },
};
