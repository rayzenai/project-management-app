<script lang="ts">
    import { router } from '@inertiajs/svelte';
    import { formatDate, formatRelative } from '../lib/format';
    import type { Task } from '../lib/types';
    import Popover from './Popover.svelte';

    let {
        task,
        projectSlug,
        size = 'md',
        ghost = false,
        onUpdated,
    }: {
        task: Pick<
            Task,
            | 'id'
            | 'slug'
            | 'deadline_at'
            | 'status'
            | 'is_late'
            | 'completed_at'
        >;
        projectSlug: string;
        size?: 'sm' | 'md';
        /** Render the empty state only on row hover (rows pass true; the Peek keeps it always visible). */
        ghost?: boolean;
        onUpdated?: (deadline_at: string | null) => void;
    } = $props();

    let open = $state(false);
    /** undefined = no pending override; null = optimistically cleared. */
    let optimistic = $state<string | null | undefined>(undefined);
    let failed = $state(false);

    const shown = $derived(
        optimistic !== undefined ? optimistic : (task.deadline_at ?? null),
    );
    const relative = $derived(formatRelative(shown));
    const overdue = $derived(relative.includes('overdue'));
    const dueSoon = $derived(
        relative === 'today' ||
            relative === 'tomorrow' ||
            /^in [1-7]d$/.test(relative),
    );

    const COMPLETE = new Set(['done']);
    const complete = $derived(COMPLETE.has(task.status ?? ''));

    $effect(() => {
        if (
            optimistic !== undefined &&
            (task.deadline_at ?? null) === optimistic
        ) {
            optimistic = undefined;
        }
    });

    function setDeadline(value: string | null) {
        open = false;

        if (value === shown) {
            return;
        }

        const previous = shown;
        optimistic = value;
        onUpdated?.(value);

        router.patch(
            `/workspace/projects/${projectSlug}/tasks/${task.slug}`,
            { deadline_at: value },
            {
                preserveState: true,
                preserveScroll: true,
                onError: () => {
                    optimistic = undefined;
                    onUpdated?.(previous);
                    failed = true;
                    setTimeout(() => (failed = false), 2000);
                },
            },
        );
    }

    const sizing = $derived(
        size === 'sm' ? 'h-5 text-[11.5px]' : 'h-6 text-xs',
    );
</script>

<Popover
    bind:open
    role="dialog"
    align="right"
    triggerLabel={shown ? `Deadline ${formatDate(shown)}` : 'Set deadline'}
>
    {#snippet trigger()}
        {#if shown && complete}
            <span
                class={`chip ${sizing} ${task.is_late ? 'chip-warn' : ''} ${failed ? 'ring-1 ring-danger' : ''}`}
            >
                {task.is_late ? 'finished late' : formatDate(shown)}
            </span>
        {:else if shown}
            <span
                class={`chip ${sizing} ${overdue ? 'chip-danger' : dueSoon ? 'chip-warn' : ''} ${failed ? 'ring-1 ring-danger' : ''}`}
            >
                {relative}
            </span>
        {:else}
            <span
                class={`chip ${sizing} border-dashed text-fg-faint ${ghost ? 'opacity-0 transition group-hover:opacity-100' : ''}`}
            >
                + date
            </span>
        {/if}
    {/snippet}

    <div class="flex items-center gap-2 px-2 py-1.5">
        <input
            type="date"
            value={shown ?? ''}
            class="input w-auto"
            onchange={(e) =>
                setDeadline(
                    (e.currentTarget as HTMLInputElement).value || null,
                )}
        />
        {#if shown}
            <button
                type="button"
                data-popover-item
                class="btn-ghost text-xs hover:text-danger"
                onclick={() => setDeadline(null)}
            >
                Clear
            </button>
        {/if}
    </div>
</Popover>
