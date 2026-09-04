<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { Check } from '@lucide/svelte';
    import type { Priority, Task } from '../lib/types';
    import Popover from './Popover.svelte';
    import PriorityBars from './PriorityBars.svelte';

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

    const OPTIONS: { value: Priority; label: string }[] = [
        { value: 'urgent', label: 'Urgent' },
        { value: 'high', label: 'High' },
        { value: 'medium', label: 'Medium' },
        { value: 'low', label: 'Low' },
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
    triggerClass={`inline-flex h-6 items-center rounded-md px-1.5 transition hover:bg-hover ${hidden ? 'opacity-0 group-hover:opacity-100' : ''} ${failed ? 'ring-1 ring-danger' : ''}`}
>
    {#snippet trigger()}
        <PriorityBars priority={shown} />
    {/snippet}

    {#each OPTIONS as option (option.value)}
        <button
            type="button"
            data-popover-item
            role="option"
            aria-selected={option.value === shown}
            class="menu-item"
            onclick={() => setPriority(option.value)}
        >
            <PriorityBars priority={option.value} />
            <span class="flex-1">{option.label}</span>
            {#if option.value === shown}<Check
                    class="h-3.5 w-3.5 text-accent"
                />{/if}
        </button>
    {/each}
</Popover>
