<script module lang="ts">
    // Sheets open on top of each other; only the top one takes the keys.
    const open: symbol[] = [];
</script>

<script lang="ts">
    import { onMount, tick } from 'svelte';
    import type { Snippet } from 'svelte';
    import { portal } from '../lib/portal';

    /**
     * The one bottom sheet, a phone's form of a popover: the to-do list,
     * the account menu, the project picker, a form that would not fit.
     * Mounted while open (`{#if open}<Sheet>`), on <body> over the dimmed
     * page. It takes the focus (the panel, so no keyboard rises over a
     * list, or with `focusFirst` its first control), keeps Tab going round
     * it (a menu opened from it, outside it, keeps its own keys), and gives
     * the focus back when it closes. Escape and a tap on the backdrop call
     * `onclose`.
     */
    let {
        label,
        labelledby,
        closeLabel,
        onclose,
        focusFirst = false,
        panelClass = '',
        wrapperClass = '',
        style,
        panel = $bindable(null),
        children,
    }: {
        /** Its name, unless `labelledby` names the heading inside. */
        label?: string;
        labelledby?: string;
        /** The backdrop's name for a screen reader: "Close the to-dos". */
        closeLabel: string;
        onclose: () => void;
        focusFirst?: boolean;
        panelClass?: string;
        wrapperClass?: string;
        style?: string;
        /** The panel, for a caller that measures it. */
        panel?: HTMLElement | null;
        children: Snippet;
    } = $props();

    const FOCUSABLE =
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const me = Symbol('sheet');

    function focusables(): HTMLElement[] {
        return Array.from(
            panel?.querySelectorAll<HTMLElement>(FOCUSABLE) ?? [],
        );
    }

    onMount(() => {
        const opener =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        open.push(me);

        void tick().then(() =>
            (focusFirst ? (focusables()[0] ?? panel) : panel)?.focus(),
        );

        return () => {
            open.splice(open.indexOf(me), 1);

            if (opener?.isConnected) {
                opener.focus({ preventScroll: true });
            }
        };
    });

    /**
     * The keys, from inside the panel (before anything listening on the
     * window, such as the shell's Escape for the dialog the sheet was
     * opened from), or from the window when the focus fell to <body> (a
     * row that held it was deleted).
     */
    function onKey(e: KeyboardEvent, fromWindow: boolean): void {
        const active = document.activeElement as HTMLElement | null;

        if (
            e.defaultPrevented ||
            open[open.length - 1] !== me ||
            (fromWindow && active !== null && active !== document.body)
        ) {
            return;
        }

        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            onclose();

            return;
        }

        if (e.key !== 'Tab' || !panel) {
            return;
        }

        const items = focusables();
        const at = items.indexOf(active as HTMLElement);

        if (items.length === 0) {
            return;
        }

        if (at === -1 || (e.shiftKey && at === 0)) {
            e.preventDefault();
            items[e.shiftKey ? items.length - 1 : 0].focus();
        } else if (!e.shiftKey && at === items.length - 1) {
            e.preventDefault();
            items[0].focus();
        }
    }
</script>

<div use:portal class={`fixed inset-0 z-50 ${wrapperClass}`}>
    <button
        type="button"
        aria-label={closeLabel}
        tabindex="-1"
        class="absolute inset-0 cursor-default bg-black/40"
        onclick={onclose}
    ></button>
    <div
        bind:this={panel}
        role="dialog"
        aria-modal="true"
        aria-label={labelledby ? undefined : label}
        aria-labelledby={labelledby}
        tabindex="-1"
        class={`popover absolute inset-x-0 bottom-0 rounded-b-none pb-[env(safe-area-inset-bottom)] focus:outline-none ${panelClass}`}
        {style}
        onkeydown={(e) => onKey(e, false)}
    >
        {@render children()}
    </div>
</div>

<svelte:window onkeydown={(e) => onKey(e, true)} />
