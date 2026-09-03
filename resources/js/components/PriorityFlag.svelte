<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import type { Priority, Task } from '../lib/types';
    import Popover from './Popover.svelte';

    let {
        task,
        projectSlug,
        quiet = false,
        onUpdated,
    }: {
        task: Pick<Task, 'id' | 'slug' | 'priority'>;
        projectSlug: string;
        /** Rows pass true: medium (the default) renders invisibly until hover to cut noise. */
        quiet?: boolean;
        onUpdated?: (priority: Priority) => void;
    } = $props();

    const OPTIONS: { value: Priority; label: string; dot: string }[] = [
        { value: 'urgent', label: 'Urgent', dot: 'bg-red-500' },
        { value: 'high', label: 'High', dot: 'bg-orange-500' },
        { value: 'medium', label: 'Medium', dot: 'bg-amber-400' },
        {
            value: 'low',
            label: 'Low',
            dot: 'bg-neutral-400 dark:bg-neutral-500',
        },
    ];

    let open = $state(false);
    let optimistic = $state<Priority | null>(null);
    let failed = $state(false);

    const shown = $derived(optimistic ?? task.priority ?? 'medium');
    const meta = $derived(OPTIONS.find((o) => o.value === shown) ?? OPTIONS[2]);
    const hidden = $derived(quiet && shown === 'medium');

    $effect(() => {
        if (optimistic !== null && task.priority === optimistic) {
            optimistic = null;
        }
    });

    function setPriority(value: Priority) {
        open = false;

        if (value === shown) {
            return;
        }

        const previous = shown;
        optimistic = value;
        onUpdated?.(value);

        router.patch(
            `/workspace/projects/${projectSlug}/tasks/${task.slug}`,
            { priority: value },
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

<Popover
    bind:open
    role="listbox"
    triggerLabel={`Priority: ${meta.label}`}
    triggerClass={hidden ? 'opacity-0 transition group-hover:opacity-100' : ''}
>
    {#snippet trigger()}
        <span
            class={`inline-block h-2.5 w-2.5 rounded-full ring-1 ring-black/10 ring-inset dark:ring-white/15 ${meta.dot} ${failed ? 'ring-2 ring-danger' : ''}`}
        ></span>
    {/snippet}

    {#each OPTIONS as option (option.value)}
        <button
            type="button"
            data-popover-item
            role="option"
            aria-selected={option.value === shown}
            class="text-fg-muted hover:bg-surface-alt flex w-full items-center gap-2 px-3 py-1.5 text-left text-sm"
            onclick={() => setPriority(option.value)}
        >
            <span
                class={`inline-block h-2.5 w-2.5 rounded-full ring-1 ring-black/10 ring-inset dark:ring-white/15 ${option.dot}`}
            ></span>
            <span class="flex-1">{option.label}</span>
            {#if option.value === shown}<span class="text-accent">✓</span>{/if}
        </button>
    {/each}
</Popover>
