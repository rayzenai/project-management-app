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
    const dueToday = $derived(relative === 'today');

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
</script>

<Popover
    bind:open
    role="dialog"
    align="right"
    triggerLabel={shown ? `Deadline ${formatDate(shown)}` : 'Set deadline'}
>
    {#snippet trigger()}
        {#if shown && complete}
            {#if task.is_late}
                <span
                    class={`inline-flex items-center gap-1 rounded-full bg-warn/10 text-warn ring-1 ring-warn/30 ring-inset font-mono whitespace-nowrap ${
                        size === 'sm'
                            ? 'px-1.5 py-px text-[10px]'
                            : 'px-2 py-0.5 text-[11px]'
                    }`}
                >
                    late
                </span>
            {:else}
                <span
                    class={`inline-flex items-center gap-1 rounded-full bg-surface-alt text-fg-muted ring-1 ring-line ring-inset font-mono whitespace-nowrap ${
                        size === 'sm'
                            ? 'px-1.5 py-px text-[10px]'
                            : 'px-2 py-0.5 text-[11px]'
                    }`}
                >
                    ⏱ {formatDate(shown)}
                </span>
            {/if}
        {:else if shown}
            <span
                class={`inline-flex items-center gap-1 rounded-full font-mono whitespace-nowrap ring-1 ring-inset ${
                    size === 'sm'
                        ? 'px-1.5 py-px text-[10px]'
                        : 'px-2 py-0.5 text-[11px]'
                } ${
                    overdue
                        ? 'bg-danger/10 text-danger ring-danger/30'
                        : dueToday
                          ? 'bg-warn/10 text-warn ring-warn/30'
                          : 'bg-surface-alt text-fg-muted ring-line'
                } ${failed ? 'ring-2 ring-danger' : ''}`}
            >
                ⏱ {relative}
            </span>
        {:else}
            <span
                class={`inline-flex items-center rounded-full px-1.5 py-px font-mono text-[10px] text-fg-faint ring-1 ring-line ring-inset ${
                    ghost ? 'opacity-0 transition group-hover:opacity-100' : ''
                }`}
            >
                + date
            </span>
        {/if}
    {/snippet}

    <div class="flex items-center gap-2 px-3 py-2">
        <input
            type="date"
            value={shown ?? ''}
            class="rounded-md border border-line bg-surface px-2 py-1 text-sm text-fg"
            onchange={(e) =>
                setDeadline(
                    (e.currentTarget as HTMLInputElement).value || null,
                )}
        />
        {#if shown}
            <button
                type="button"
                data-popover-item
                class="font-mono text-xs text-fg-muted hover:text-danger"
                onclick={() => setDeadline(null)}
            >
                Clear
            </button>
        {/if}
    </div>
</Popover>
