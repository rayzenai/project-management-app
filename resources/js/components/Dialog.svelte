<script lang="ts">
    import { X } from '@lucide/svelte';
    import { onMount, tick } from 'svelte';
    import type { Snippet } from 'svelte';
    import { t } from '../lib/i18n';
    import { portal } from '../lib/portal';

    /**
     * The one modal dialog: a panel over the dimmed page, a third of the
     * way down (the page scrolls when it is taller), or `center`ed and no
     * taller than the window, its content scrolling inside. The title bar
     * names it and has the close button, unless `bar` replaces it (the
     * command palette's search). Escape, the close button and a press that
     * starts and ends on the backdrop all call `onclose`; a press inside
     * released out there (a text selection dragged too far) never does.
     * Mounted while open (`{#if open}<Dialog>`): it sits on <body>, so a
     * dialog opened from the top bar covers the whole page, holds the
     * page's scroll, takes the focus (`[data-autofocus]` inside, else the
     * panel, unless the caller already put it inside) and gives it back
     * when it closes.
     */
    let {
        label,
        onclose,
        width = 520,
        center = false,
        bar,
        children,
    }: {
        /** Its name, and the title bar's words. */
        label: string;
        onclose: () => void;
        /** The panel's width in pixels, at most the window's less 32. */
        width?: number;
        center?: boolean;
        /** In place of the title bar. */
        bar?: Snippet;
        children: Snippet;
    } = $props();

    let panel = $state<HTMLElement | null>(null);
    let pressedBackdrop = false;

    onMount(() => {
        const opener =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        void tick().then(() => {
            if (panel && !panel.contains(document.activeElement)) {
                (
                    panel.querySelector<HTMLElement>('[data-autofocus]') ??
                    panel
                ).focus();
            }
        });

        return () => {
            document.body.style.overflow = overflow;

            if (opener?.isConnected) {
                opener.focus({ preventScroll: true });
            }
        };
    });

    function onKeydown(e: KeyboardEvent): void {
        if (e.key === 'Escape' && !e.defaultPrevented) {
            e.preventDefault();
            e.stopPropagation();
            onclose();
        }
    }
</script>

<div
    use:portal
    class={`fixed inset-0 z-50 bg-black/40 ${center ? 'grid place-items-center p-4' : 'overflow-y-auto'}`}
    role="presentation"
    onpointerdown={(e) => (pressedBackdrop = e.target === e.currentTarget)}
    onclick={(e) => {
        if (pressedBackdrop && e.target === e.currentTarget) {
            onclose();
        }
    }}
>
    <div
        bind:this={panel}
        role="dialog"
        aria-modal="true"
        aria-label={label}
        tabindex="-1"
        class={`popover flex flex-col overflow-hidden p-0 focus:outline-none ${center ? 'max-h-[min(640px,calc(100dvh-2rem))]' : 'mx-auto mt-[10vh] mb-12'}`}
        style={`width: min(${width}px, calc(100vw - 32px))`}
        onkeydown={onKeydown}
    >
        {#if bar}
            {@render bar()}
        {:else}
            <div
                class="flex h-12 shrink-0 items-center justify-between gap-3 border-b border-line px-4"
            >
                <h2 class="min-w-0 truncate text-[15px] font-semibold text-fg">
                    {label}
                </h2>
                <button
                    type="button"
                    class="btn-icon shrink-0"
                    title={t('Close (esc)')}
                    aria-label={t('Close')}
                    onclick={onclose}
                >
                    <X class="h-4 w-4" />
                </button>
            </div>
        {/if}
        {@render children()}
    </div>
</div>
