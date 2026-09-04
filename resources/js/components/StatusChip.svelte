<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import { Check } from '@lucide/svelte';
    import type { SharedProps, Task } from '../lib/types';
    import Popover from './Popover.svelte';
    import StatusGlyph from './StatusGlyph.svelte';

    let {
        task,
        projectSlug,
        size = 'md',
        onUpdated,
    }: {
        task: Pick<
            Task,
            'id' | 'slug' | 'status' | 'status_label' | 'status_color'
        >;
        projectSlug: string;
        size?: 'sm' | 'md';
        onUpdated?: (status: string) => void;
    } = $props();

    let open = $state(false);
    let optimistic = $state<string | null>(null);
    let failed = $state(false);

    const statuses = $derived(
        ((page.props ?? {}) as unknown as SharedProps).statuses ?? [],
    );
    const shown = $derived(optimistic ?? task.status);
    const meta = $derived(statuses.find((s) => s.value === shown));
    const label = $derived(meta?.label ?? task.status_label ?? shown);

    $effect(() => {
        if (optimistic !== null && task.status === optimistic) {
            optimistic = null;
        }
    });

    function setStatus(value: string) {
        open = false;

        if (value === shown) {
            return;
        }

        const previous = shown;
        optimistic = value;
        onUpdated?.(value);

        router.patch(
            `/workspace/projects/${projectSlug}/tasks/${task.slug}`,
            { status: value },
            {
                preserveState: true,
                preserveScroll: true,
                onError: () => {
                    optimistic = null;
                    onUpdated?.(previous);
                    failed = true;
                    setTimeout(() => (failed = false), 2000);
                },
            },
        );
    }
</script>

<Popover bind:open role="listbox" triggerLabel={`Status: ${label}`}>
    {#snippet trigger()}
        <span
            class={`inline-flex items-center gap-1.5 rounded-md font-medium whitespace-nowrap text-fg-muted transition hover:bg-hover hover:text-fg ${
                size === 'sm' ? 'h-6 px-1.5 text-xs' : 'h-7 px-2 text-[13px]'
            } ${failed ? 'ring-1 ring-danger' : ''}`}
        >
            <StatusGlyph status={shown} size={size === 'sm' ? 12 : 14} />
            {label}
        </span>
    {/snippet}

    {#each statuses as s (s.value)}
        <button
            type="button"
            data-popover-item
            role="option"
            aria-selected={s.value === shown}
            class="menu-item"
            onclick={() => setStatus(s.value)}
        >
            <StatusGlyph status={s.value} size={13} />
            <span class="flex-1">{s.label}</span>
            {#if s.value === shown}<Check
                    class="h-3.5 w-3.5 text-accent"
                />{/if}
        </button>
    {/each}
</Popover>
