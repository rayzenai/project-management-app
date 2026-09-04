<script lang="ts">
    import { page } from '@inertiajs/svelte';
    import { peek } from '../../lib/peek.svelte';
    import type { SharedProps, Task } from '../../lib/types';
    import AssigneeStack from '../AssigneeStack.svelte';
    import DateChip from '../DateChip.svelte';
    import PriorityFlag from '../PriorityFlag.svelte';

    let {
        task,
        projectSlug,
        index,
        isDragging,
        onpointerdown,
    }: {
        task: Task;
        projectSlug: string;
        index: number;
        isDragging: boolean;
        /** Board-level pointer drag; undefined for non-droppable columns. */
        onpointerdown?: (e: PointerEvent) => void;
    } = $props();

    const team = $derived(
        ((page.props ?? {}) as unknown as SharedProps).quickAddContext?.team ??
            [],
    );
</script>

<!-- svelte-ignore a11y_no_noninteractive_tabindex, a11y_no_noninteractive_element_interactions -->
<div
    role="listitem"
    data-card-index={index}
    {onpointerdown}
    onclick={() => peek.open({ id: task.id, slug: task.slug })}
    onkeydown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            peek.open({ id: task.id, slug: task.slug });
        }
    }}
    tabindex="0"
    class={`group cursor-grab touch-pan-y rounded-lg border border-line bg-raised px-3 py-2.5 transition select-none hover:border-accent active:cursor-grabbing ${
        isDragging ? 'opacity-40' : ''
    }`}
>
    <div class="flex h-5 items-center justify-between gap-2">
        <span class="font-mono text-[11px] text-fg-faint tabular-nums">
            {task.item_number ?? ''}
        </span>
        <AssigneeStack {task} {team} size="sm" />
    </div>

    <div class="mt-1 leading-snug font-medium text-fg">
        {task.short_title || task.title}
    </div>
    {#if task.title_np}
        <div class="font-np mt-0.5 text-xs leading-snug text-fg-muted">
            {task.title_np}
        </div>
    {/if}

    <div class="mt-2 flex items-center gap-1.5">
        <DateChip {task} {projectSlug} size="sm" ghost />
        <PriorityFlag {task} {projectSlug} quiet />
    </div>
</div>
