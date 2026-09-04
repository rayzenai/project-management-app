<script lang="ts">
    import type { Snippet } from 'svelte';

    let {
        open = $bindable(false),
        align = 'left',
        role = 'menu',
        triggerClass = '',
        triggerLabel,
        panelClass = '',
        trigger,
        children,
    }: {
        open?: boolean;
        align?: 'left' | 'right';
        role?: 'menu' | 'listbox' | 'dialog';
        triggerClass?: string;
        triggerLabel?: string;
        panelClass?: string;
        trigger: Snippet<[{ open: boolean }]>;
        children: Snippet;
    } = $props();

    let wrapper = $state<HTMLElement | null>(null);
    let panel = $state<HTMLElement | null>(null);
    let triggerEl = $state<HTMLButtonElement | null>(null);
    let placement = $state('');

    const GAP = 4;
    const EDGE = 8;

    /**
     * The panel is moved to <body> and positioned in viewport coordinates.
     * Absolute positioning inside the trigger's own stacking context looked
     * right until a toolbar scrolled sideways: `overflow-x: auto` computes
     * `overflow-y` to `auto` as well, so the menu was clipped to the strip it
     * dropped out of. Nothing can clip a child of <body>.
     */
    function portal(node: HTMLElement) {
        document.body.appendChild(node);

        return {
            destroy() {
                node.remove();
            },
        };
    }

    /** Anchors the panel under the trigger, flipping up and clamping to fit. */
    function place() {
        const anchor = triggerEl?.getBoundingClientRect();

        if (!anchor || !panel) {
            return;
        }

        const width = panel.offsetWidth;
        const height = panel.offsetHeight;
        const below = window.innerHeight - anchor.bottom - GAP - EDGE;
        const above = anchor.top - GAP - EDGE;
        const flip = height > below && above > below;

        const left = Math.max(
            EDGE,
            Math.min(
                align === 'right' ? anchor.right - width : anchor.left,
                window.innerWidth - width - EDGE,
            ),
        );
        const top = flip
            ? Math.max(EDGE, anchor.top - GAP - Math.min(height, above))
            : anchor.bottom + GAP;

        placement =
            `left:${Math.round(left)}px;top:${Math.round(top)}px;` +
            `max-height:${Math.round(Math.max(flip ? above : below, 120))}px;`;
    }

    function items(): HTMLElement[] {
        return Array.from(
            panel?.querySelectorAll<HTMLElement>('[data-popover-item]') ?? [],
        );
    }

    $effect(() => {
        if (!open) {
            placement = '';

            return;
        }

        place();

        queueMicrotask(() => {
            place();
            const first =
                items()[0] ??
                panel?.querySelector<HTMLElement>('input, button, [tabindex]');
            first?.focus();
        });

        function onPointerDown(event: PointerEvent) {
            const target = event.target as Node;

            if (
                wrapper?.contains(target) !== true &&
                panel?.contains(target) !== true
            ) {
                open = false;
            }
        }

        // The trigger travels with the page, so the panel has to follow it —
        // `true` catches scrolls in any ancestor, not just the window.
        function reposition() {
            place();
        }

        document.addEventListener('pointerdown', onPointerDown, true);
        window.addEventListener('scroll', reposition, true);
        window.addEventListener('resize', reposition);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown, true);
            window.removeEventListener('scroll', reposition, true);
            window.removeEventListener('resize', reposition);
        };
    });

    function onPanelKeydown(event: KeyboardEvent) {
        if (event.key === 'Escape') {
            event.stopPropagation();
            open = false;
            triggerEl?.focus();

            return;
        }

        if (
            event.key !== 'ArrowDown' &&
            event.key !== 'ArrowUp' &&
            event.key !== 'Home' &&
            event.key !== 'End'
        ) {
            return;
        }

        const list = items();

        if (list.length === 0) {
            return;
        }

        event.preventDefault();

        const current = list.indexOf(document.activeElement as HTMLElement);
        let next = 0;

        if (event.key === 'ArrowDown') {
            next = (current + 1) % list.length;
        }

        if (event.key === 'ArrowUp') {
            next = (current - 1 + list.length) % list.length;
        }

        if (event.key === 'End') {
            next = list.length - 1;
        }

        list[next]?.focus();
    }
</script>

<span bind:this={wrapper} class="relative inline-flex">
    <button
        bind:this={triggerEl}
        type="button"
        class={triggerClass}
        aria-haspopup={role}
        aria-expanded={open}
        aria-label={triggerLabel}
        onclick={(e) => {
            e.stopPropagation();
            open = !open;
        }}
        onkeydown={(e) => e.stopPropagation()}
    >
        {@render trigger({ open })}
    </button>

    {#if open}
        <div
            bind:this={panel}
            use:portal
            {role}
            tabindex="-1"
            class={`popover fixed z-50 min-w-44 overflow-auto px-1 ${panelClass}`}
            style={placement}
            onkeydown={onPanelKeydown}
            onclick={(e) => e.stopPropagation()}
        >
            {@render children()}
        </div>
    {/if}
</span>
