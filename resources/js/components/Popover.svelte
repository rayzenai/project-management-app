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

    function items(): HTMLElement[] {
        return Array.from(
            panel?.querySelectorAll<HTMLElement>('[data-popover-item]') ?? [],
        );
    }

    $effect(() => {
        if (!open) {
            return;
        }

        queueMicrotask(() => {
            const first =
                items()[0] ??
                panel?.querySelector<HTMLElement>('input, button, [tabindex]');
            first?.focus();
        });

        function onPointerDown(event: PointerEvent) {
            if (wrapper && !wrapper.contains(event.target as Node)) {
                open = false;
            }
        }

        document.addEventListener('pointerdown', onPointerDown, true);

        return () =>
            document.removeEventListener('pointerdown', onPointerDown, true);
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
            {role}
            tabindex="-1"
            class={`absolute top-full z-30 mt-1 min-w-44 rounded-md border border-line bg-surface py-1 shadow-lg ${align === 'right' ? 'right-0' : 'left-0'} ${panelClass}`}
            onkeydown={onPanelKeydown}
            onclick={(e) => e.stopPropagation()}
        >
            {@render children()}
        </div>
    {/if}
</span>
