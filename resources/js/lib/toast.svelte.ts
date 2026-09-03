// Global toast stack rendered once in AppShell. Any component can call
// toast.show(...) — completion checkboxes pass an undo callback, the Inertia
// flash handler reports server messages, drag-drop reports failures.

import { SvelteMap } from 'svelte/reactivity';

export type ToastVariant = 'success' | 'error' | 'info';

export interface ToastItem {
    id: number;
    message: string;
    variant: ToastVariant;
    undo: { label: string; run: () => void | Promise<void> } | null;
}

const DURATION = 6000;
const MAX = 3;

let items = $state<ToastItem[]>([]);
const timers = new SvelteMap<number, ReturnType<typeof setTimeout>>();
let nextId = 1;

function dismiss(id: number): void {
    const timer = timers.get(id);

    if (timer) {
        clearTimeout(timer);
    }

    timers.delete(id);
    items = items.filter((item) => item.id !== id);
}

function schedule(id: number, duration: number): void {
    timers.set(
        id,
        setTimeout(() => dismiss(id), duration),
    );
}

function show(
    message: string,
    options: {
        variant?: ToastVariant;
        undo?: { label?: string; run: () => void | Promise<void> };
        duration?: number;
    } = {},
): number {
    const id = nextId++;

    items = [
        ...items,
        {
            id,
            message,
            variant: options.variant ?? 'success',
            undo: options.undo
                ? { label: options.undo.label ?? 'Undo', run: options.undo.run }
                : null,
        },
    ];

    while (items.length > MAX) {
        dismiss(items[0].id);
    }

    schedule(id, options.duration ?? DURATION);

    return id;
}

export const toast = {
    get items(): ToastItem[] {
        return items;
    },
    show,
    error(message: string): number {
        return show(message, { variant: 'error' });
    },
    dismiss,
    async undo(id: number): Promise<void> {
        const item = items.find((entry) => entry.id === id);

        if (item?.undo) {
            await item.undo.run();
        }

        dismiss(id);
    },
    pause(id: number): void {
        const timer = timers.get(id);

        if (timer) {
            clearTimeout(timer);
        }

        timers.delete(id);
    },
    resume(id: number): void {
        if (items.some((item) => item.id === id) && !timers.has(id)) {
            schedule(id, DURATION);
        }
    },
};
