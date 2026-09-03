<script lang="ts">
    import { page, router } from '@inertiajs/svelte';
    import type { SharedProps, Task } from '../lib/types';
    import Popover from './Popover.svelte';

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
    const color = $derived(meta?.color ?? task.status_color ?? '#9CA3AF');

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
            class={`inline-flex items-center gap-1 rounded-full font-medium whitespace-nowrap ring-1 ring-inset ${
                size === 'sm'
                    ? 'px-1.5 py-px text-[10px]'
                    : 'px-2 py-0.5 text-xs'
            } ${failed ? 'ring-2 ring-danger' : ''}`}
            style={`background-color:${color}1a; color:${color}; --tw-ring-color:${color}40;`}
        >
            <span class="h-1.5 w-1.5 rounded-full" style={`background:${color}`}
            ></span>
            {label}
        </span>
    {/snippet}

    {#each statuses as s (s.value)}
        <button
            type="button"
            data-popover-item
            role="option"
            aria-selected={s.value === shown}
            class="flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm text-fg-muted hover:bg-surface-alt"
            onclick={() => setStatus(s.value)}
        >
            <span
                class="h-2 w-2 shrink-0 rounded-full"
                style={`background:${s.color}`}
            ></span>
            <span class="flex-1">{s.label}</span>
            {#if s.value === shown}<span class="text-accent">✓</span>{/if}
        </button>
    {/each}
</Popover>
